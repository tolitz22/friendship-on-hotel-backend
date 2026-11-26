<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NoShowRefundEligibility
{
    /** Caller must hold the hotel and folio write locks when authorizing a refund. */
    public function review(Hotel $hotel, int $bookingId, ?int $reviewingRefundId = null): array
    {
        $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId);
        $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)->where('id', $bookingId)->first();
        abort_unless($booking !== null && $booking->lifecycle_state === 'no_show', 409, 'Booking is not a no-show.');
        $clearance = self::clearance((int) $hotel->id, $bookingId, (int) $folio->id);
        $statement = app(FolioSettlementService::class)->statement($hotel, $bookingId);
        $net = $statement['netRecordedChargesMinor'];
        $verified = $statement['verifiedPaymentsMinor'];
        $allocated = $statement['verifiedAllocatedMinor'];
        $reserved = 0;
        $pendingRefunds = 0;
        foreach ($statement['refunds'] as $refund) {
            if (!in_array($refund['status'], ['requested', 'approved'], true)) {
                continue;
            }
            $completed = $refund['status'] === 'approved' && $refund['evidenceStatus'] === 'verified';
            // Only release the reservation for a pending request under review.
            // Already disbursed funds must never become available again.
            if (!$completed && (string) $reviewingRefundId === $refund['id']) {
                continue;
            }
            $reserved += $refund['amountMinor'];
            if (!$completed) {
                $pendingRefunds += $refund['amountMinor'];
            }
        }
        $valid = $clearance !== null && $net === (int) $clearance->gross_minor;
        $verifiedRefunds = $statement['verifiedRefundsMinor'];
        $netVerifiedFunds = $verified - $verifiedRefunds;
        $unallocatedFunds = $netVerifiedFunds - $allocated;
        $reconciled = $valid && $statement['paymentEvidenceIndependentlyReviewed']
            && $allocated >= 0 && $allocated <= $net && $unallocatedFunds >= 0
            && $pendingRefunds <= $unallocatedFunds;
        $ready = $reconciled
            && config('financial_accounting.enable_verified_settlement', false)
            && NoShowWorkflow::enabled((int) $hotel->id);
        $excess = $ready ? max(0, $verified - max($net, $allocated) - $reserved) : 0;
        $payments = [];
        foreach ($statement['payments'] as $payment) {
            if ($payment['status'] !== 'staff_confirmed' || $payment['verificationStatus'] !== 'verified'
                || $payment['method'] !== 'bank_transfer') {
                continue;
            }
            $used = 0;
            foreach ($statement['allocations'] as $allocation) {
                if ($allocation['paymentId'] === $payment['id']) $used += $allocation['amountMinor'];
            }
            foreach ($statement['refunds'] as $refund) {
                if ($refund['paymentId'] !== $payment['id'] || !in_array($refund['status'], ['requested', 'approved'], true)) {
                    continue;
                }
                $completed = $refund['status'] === 'approved' && $refund['evidenceStatus'] === 'verified';
                if ($completed || (string) $reviewingRefundId !== $refund['id']) {
                    $used += $refund['amountMinor'];
                }
            }
            $payments[] = ['paymentId' => $payment['id'], 'maximumRequestMinor' => min($excess, max(0, $payment['amountMinor'] - $used))];
        }
        $maximumRefund = min($excess, array_sum(array_column($payments, 'maximumRequestMinor')));

        return [
            'financialClearanceValid' => $valid,
            'canRequestRefund' => $maximumRefund > 0,
            'verifiedPaymentsMinor' => $verified, 'reservedRefundsMinor' => $reserved,
            'verifiedRefundsMinor' => $verifiedRefunds,
            'pendingRefundsMinor' => $pendingRefunds,
            'netVerifiedFundsMinor' => $netVerifiedFunds,
            'unallocatedVerifiedFundsMinor' => $reconciled ? $unallocatedFunds : null,
            'unallocatedFeeMinor' => $reconciled ? $net - $allocated : null,
            'reconciliationValid' => $reconciled,
            'remainingCollectionMinor' => $valid ? max(0, $net - $verified + $statement['verifiedRefundsMinor']) : null,
            'maximumRefundRequestMinor' => $maximumRefund,
            'settled' => $reviewingRefundId === null && $reconciled && $allocated === $net && $unallocatedFunds === 0
                && $pendingRefunds === 0, 'payments' => $payments,
            'refundIssued' => false,
        ];
    }

    public static function clearance(int $hotelId, int $bookingId, int $folioId): ?object
    {
        if (!Schema::hasTable('no_show_financial_clearances') || !Schema::hasTable('no_show_financial_reviews')) {
            return null;
        }

        $query = DB::table('no_show_financial_clearances as c')
            ->join('no_show_financial_reviews as v', 'v.id', '=', 'c.review_id')
            ->where('c.hotel_id', $hotelId)->where('v.hotel_id', $hotelId)
            ->where('v.folio_id', $folioId)->where('v.reservation_record_id', $bookingId);
        NoShowClosingGuard::requireValidClearance($query);

        return $query->latest('c.id')->first(['c.*']);
    }
}

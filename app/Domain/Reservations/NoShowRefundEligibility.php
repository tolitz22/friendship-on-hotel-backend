<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NoShowRefundEligibility
{
    /** Caller must hold the hotel and folio write locks when authorizing a refund. */
    public function review(Hotel $hotel, int $bookingId): array
    {
        $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId);
        $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)->where('id', $bookingId)->first();
        abort_unless($booking !== null && $booking->lifecycle_state === 'no_show', 409, 'Booking is not a no-show.');
        $clearance = Schema::hasTable('no_show_financial_clearances') && Schema::hasTable('no_show_financial_reviews')
            ? DB::table('no_show_financial_clearances as c')
                ->join('no_show_financial_reviews as v', 'v.id', '=', 'c.review_id')
                ->where('c.hotel_id', $hotel->id)->where('v.hotel_id', $hotel->id)
                ->where('v.folio_id', $folio->id)->where('v.reservation_record_id', $bookingId)
                ->whereColumn('c.gross_minor', 'v.fee_minor')->whereColumn('c.reviewed_by', '!=', 'v.actor_id')
                ->first(['c.*']) : null;
        $statement = app(FolioSettlementService::class)->statement($hotel, $bookingId);
        $net = $statement['netRecordedChargesMinor'];
        $verified = $statement['verifiedPaymentsMinor'];
        $allocated = $statement['verifiedAllocatedMinor'];
        $reserved = 0;
        foreach ($statement['refunds'] as $refund) {
            if (in_array($refund['status'], ['requested', 'approved'], true)) {
                $reserved += $refund['amountMinor'];
            }
        }
        $validJournal = $clearance !== null && ((int) $clearance->gross_minor === 0
            ? $clearance->journal_id === null
            : DB::table('financial_posting_journals as j')->where('j.id', $clearance->journal_id)
                ->where('j.hotel_id', $hotel->id)->where('j.source_type', 'no_show_fee')
                ->whereNotExists(function ($q): void {
                    $q->selectRaw('1')->from('financial_posting_journals as x')->whereColumn('x.reverses_journal_id', 'j.id');
                })->exists());
        $ready = $validJournal && $net === (int) $clearance->gross_minor
            && $allocated >= 0 && $allocated <= $net
            && $statement['paymentEvidenceIndependentlyReviewed']
            && config('financial_accounting.enable_verified_settlement', false);
        $excess = $ready ? max(0, $verified - max($net, $allocated) - $reserved) : 0;
        $payments = [];
        foreach ($statement['payments'] as $payment) {
            if ($payment['status'] !== 'staff_confirmed' || $payment['verificationStatus'] !== 'verified') continue;
            $used = 0;
            foreach ($statement['allocations'] as $allocation) {
                if ($allocation['paymentId'] === $payment['id']) $used += $allocation['amountMinor'];
            }
            foreach ($statement['refunds'] as $refund) {
                if ($refund['paymentId'] === $payment['id'] && in_array($refund['status'], ['requested', 'approved'], true)) {
                    $used += $refund['amountMinor'];
                }
            }
            $payments[] = ['paymentId' => $payment['id'], 'maximumRequestMinor' => min($excess, max(0, $payment['amountMinor'] - $used))];
        }
        return [
            'financialClearanceValid' => $validJournal, 'canRequestRefund' => $ready && $excess > 0,
            'verifiedPaymentsMinor' => $verified, 'reservedRefundsMinor' => $reserved,
            'remainingCollectionMinor' => $ready ? max(0, $net - $verified + $reserved) : null,
            'maximumRefundRequestMinor' => $excess, 'payments' => $payments,
            'refundIssued' => false,
        ];
    }
}

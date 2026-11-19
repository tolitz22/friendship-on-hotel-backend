<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Opt-in operational cash checkout only. This is NOT a signed financial
 * settlement, tax invoice, bank verification, or final shift reconciliation.
 * The cashier remains responsible for cash close and independent review.
 */
final class SimplifiedCashCheckoutPolicy
{
    public function enabled(): bool
    {
        return (bool) config('financial_accounting.simplified_cashier_mode', false);
    }

    /** Called inside the reservation transition's hotel-wide transaction lock. */
    public function requireEligible(Hotel $hotel, int $bookingId): void
    {
        if (! $this->enabled()) {
            throw new \LogicException('Simplified cash checkout policy is disabled.');
        }
        $folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
            ->where('reservation_record_id', $bookingId)->lockForUpdate()->first();
        if ($folio === null || ! Schema::hasTable('cashier_shift_receipts')) {
            throw new HttpException(409, 'An auditable guest folio and cashier receipts are required.');
        }
        $statement = app(FolioSettlementService::class)->statement($hotel, $bookingId);
        $this->validateStatement($hotel, $bookingId, $statement);
    }

    /** Preview only: checkout re-checks eligibility under the hotel lock. */
    public function readiness(Hotel $hotel, int $bookingId, array $statement): array
    {
        if (! $this->enabled()) {
            return ['eligible' => false, 'reason' => 'Simplified cashier mode is disabled.'];
        }
        try {
            $this->validateStatement($hotel, $bookingId, $statement);
            return ['eligible' => true, 'reason' => null];
        } catch (HttpException $exception) {
            return ['eligible' => false, 'reason' => $exception->getMessage()];
        }
    }

    private function validateStatement(Hotel $hotel, int $bookingId, array $statement): void
    {
        if ($statement['signed']) {
            if (! $statement['signedSnapshotIntegrity'] || ! $statement['canSignOff']) {
                throw new HttpException(409, 'Signed statement failed financial integrity checks.');
            }
            return;
        }
        $charges = (int) $statement['netRecordedChargesMinor'];
        if ($charges <= 0) {
            throw new HttpException(409, 'Zero or negative guest charges require a reviewed complimentary-stay or credit policy.');
        }
        if ($statement['refunds'] !== [] || $statement['adjustments'] !== []) {
            throw new HttpException(409, 'Refunds and manual bill adjustments require reviewed settlement.');
        }
        // Avoid bypassing tax and unsettled accounting assessment controls.
        if (config('financial_accounting.enable_live_folio_tax', false)
            || (Schema::hasTable('financial_folio_night_assessments')
                && DB::table('financial_folio_night_assessments')
                    ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                    ->where('status', 'pending')->exists())) {
            throw new HttpException(409, 'Tax assessment requires reviewed financial settlement.');
        }

        $sum = 0;
        foreach ($statement['payments'] as $payment) {
            if ($payment['status'] === 'voided') {
                continue;
            }
            if ($payment['status'] === 'rejected') {
                throw new HttpException(409, 'Rejected payment evidence requires owner finance review before checkout.');
            }
            if ($payment['method'] !== 'cash' || $payment['status'] !== 'staff_confirmed') {
                throw new HttpException(409, 'Only staff-confirmed cash receipts qualify for simplified checkout.');
            }
            $receipt = DB::table('cashier_shift_receipts as receipt')
                ->join('cashier_shifts as shift', function ($join): void {
                    $join->on('shift.id', '=', 'receipt.shift_id')
                        ->on('shift.hotel_id', '=', 'receipt.hotel_id');
                })
                ->where('receipt.hotel_id', $hotel->id)
                ->where('receipt.payment_id', (int) $payment['id'])
                ->where('receipt.reservation_record_id', $bookingId)
                ->first(['receipt.amount_minor', 'receipt.cash_tendered_minor',
                    'receipt.change_given_minor', 'receipt.net_receipt_minor',
                    'shift.status']);
            if ($receipt === null || ! in_array($receipt->status, ['open', 'submitted', 'reconciled'], true)) {
                throw new HttpException(409, 'Cash payment requires an active auditable cashier shift.');
            }
            $amount = (int) $payment['amountMinor'];
            if ((int) $receipt->amount_minor !== $amount
                || $receipt->cash_tendered_minor === null
                || $receipt->change_given_minor === null
                || $receipt->net_receipt_minor === null
                || (int) $receipt->net_receipt_minor !== $amount
                || (int) $receipt->cash_tendered_minor - (int) $receipt->change_given_minor !== $amount) {
                throw new HttpException(409, 'Cash tender/change details must reconcile before checkout.');
            }
            $sum += $amount;
        }
        if ($sum !== $charges) {
            throw new HttpException(409,
                'Recorded cash receipts do not exactly cover guest charges; review the folio before checkout.');
        }
        if ($statement['verifiedRefundsMinor'] > 0 || $statement['allocations'] !== []) {
            throw new HttpException(409, 'Existing financial allocations or refunds require reviewed settlement.');
        }
    }
}

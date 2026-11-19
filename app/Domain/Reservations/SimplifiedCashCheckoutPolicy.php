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

    /**
     * Save the immutable operational checkout evidence while the reservation
     * transition holds the hotel lock. This is NOT a financial settlement signoff.
     */
    public function recordCheckout(Hotel $hotel, int $bookingId, int $actorId): void
    {
        if (! Schema::hasTable('simplified_cash_checkout_audits')) {
            throw new HttpException(409, 'Simplified cash checkout audit migration is required before activation.');
        }
        $folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
            ->where('reservation_record_id', $bookingId)->first();
        abort_unless($folio !== null, 404);
        $statement = app(FolioSettlementService::class)->statement($hotel, $bookingId);
        $this->validateStatement($hotel, $bookingId, $statement);
        // Already-signed financial settlements remain on the strict path.
        // Never manufacture a cash checkout audit for a bank/mixed signed folio.
        if ($statement['signed']) {
            return;
        }

        $receipts = DB::table('cashier_shift_receipts as receipt')
            ->join('folio_payment_records as payment', function ($join): void {
                $join->on('payment.id', '=', 'receipt.payment_id')
                    ->on('payment.hotel_id', '=', 'receipt.hotel_id');
            })
            ->where('receipt.hotel_id', $hotel->id)
            ->where('receipt.reservation_record_id', $bookingId)
            ->where('payment.folio_id', $folio->id)
            ->where('payment.status', 'staff_confirmed')
            ->orderBy('receipt.payment_id')
            ->get(['receipt.payment_id', 'receipt.shift_id',
                'receipt.amount_minor', 'receipt.cash_tendered_minor',
                'receipt.change_given_minor', 'receipt.net_receipt_minor'])
            ->map(fn (object $row): array => [
                'paymentId' => (string) $row->payment_id,
                'cashierShiftId' => (string) $row->shift_id,
                'cashAmountMinor' => (int) $row->amount_minor,
                'cashTenderedMinor' => (int) $row->cash_tendered_minor,
                'changeGivenMinor' => (int) $row->change_given_minor,
                'netReceiptMinor' => (int) $row->net_receipt_minor,
            ])->all();
        $snapshot = json_encode([
            'bookingId' => (string) $bookingId,
            'folioId' => (string) $folio->id,
            'recordedChargesMinor' => (int) $statement['netRecordedChargesMinor'],
            'receipts' => $receipts,
        ], JSON_THROW_ON_ERROR);
        DB::table('simplified_cash_checkout_audits')->insert([
            'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
            'folio_id' => $folio->id, 'performed_by' => $actorId,
            'recorded_charges_minor' => (int) $statement['netRecordedChargesMinor'],
            'net_cash_minor' => array_sum(array_column($receipts, 'netReceiptMinor')),
            'receipt_count' => count($receipts),
            'receipt_snapshot' => $snapshot,
            'receipt_snapshot_sha256' => hash('sha256', $snapshot),
            'checked_out_at' => now(), 'created_at' => now(),
        ]);
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
        if (! Schema::hasTable('simplified_cash_checkout_audits')) {
            throw new HttpException(409, 'Operational cash checkout audit migration is not installed.');
        }
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

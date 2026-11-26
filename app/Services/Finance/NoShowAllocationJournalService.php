<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\NoShowRefundEligibility;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Internal posting guard. Call only from the locked folio allocation transaction. */
final class NoShowAllocationJournalService
{
    public function post(Hotel $hotel, int $bookingId, int $folioId, int $allocationId, int $actorId): int
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'Allocation transaction required.');
        abort_unless(config('financial_accounting.enable_guest_deposit_posting', false)
            && config('financial_accounting.enable_no_show_allocation_posting', false)
            && config('financial_accounting.enable_verified_settlement', false)
            && \App\Domain\Reservations\NoShowWorkflow::enabled((int) $hotel->id), 409,
            'No-show allocation journal posting is disabled.');
        $hotelId = (int) $hotel->id;
        $allocation = DB::table('folio_settlement_allocations')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->where('id', $allocationId)->first();
        abort_unless($allocation !== null && $allocation->folio_entry_id === null, 409, 'Folio allocation required.');
        $clearance = NoShowRefundEligibility::clearance($hotelId, $bookingId, $folioId);
        $review = app(NoShowRefundEligibility::class)->review($hotel, $bookingId);
        abort_unless($clearance !== null && $review['reconciliationValid'] && (int) $clearance->gross_minor > 0,
            409, 'Valid no-show fee reconciliation required.');
        $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotelId);
        $paymentId = (int) $allocation->payment_record_id;
        $payment = DB::table('folio_payment_records')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->where('id', $paymentId)->first();
        abort_unless($payment !== null && $payment->status === 'staff_confirmed', 409);
        $receipts = (clone $journals)->where('source_reference', 'folio-payment-'.$paymentId)
            ->whereIn('source_type', ['guest_deposit_receipt', 'guest_deposit_opening_reclassification'])->get();
        abort_unless($receipts->count() === 1, 409, 'Exactly one recognized deposit journal required.');
        $receipt = $receipts->first();
        abort_if((clone $journals)->where('reverses_journal_id', $receipt->id)->exists(), 409, 'Deposit journal reversed.');
        $credit = DB::table('financial_posting_lines')->where('journal_id', $receipt->id)
            ->where('account_code', 'guest_deposit_liability')->get();
        abort_unless($credit->count() === 1 && (int) $credit->first()->debit_minor === 0
            && (int) $credit->first()->credit_minor === (int) $payment->amount_minor, 409, 'Deposit liability mismatch.');
        $existing = (clone $journals)->where('idempotency_key', 'no-show-allocation-'.$allocationId)->first();
        abort_if($existing !== null && (clone $journals)->where('reverses_journal_id', $existing->id)->exists(),
            409, 'Allocation journal reversed; correction required.');
        $amount = (int) $allocation->amount_minor;
        abort_unless($amount > 0, 409);
        // Reject legacy allocations or reversals rather than silently backfill.
        $allocations = DB::table('folio_settlement_allocations')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->get();
        foreach ($allocations as $other) {
            if ((int) $other->id === $allocationId) {
                continue;
            }
            $previous = (clone $journals)->where('idempotency_key', 'no-show-allocation-'.$other->id)
                ->where('source_type', 'no_show_deposit_allocation')
                ->where('source_reference', 'folio-allocation-'.$other->id)->first();
            abort_unless($previous !== null && !(clone $journals)->where('reverses_journal_id', $previous->id)->exists(),
                409, 'Previous allocation requires journal reconciliation.');
            $lines = DB::table('financial_posting_lines')->where('journal_id', $previous->id)->orderBy('line_number')->get();
            abort_unless($lines->count() === 2 && $lines[0]->account_code === 'guest_deposit_liability'
                && (int) $lines[0]->debit_minor === (int) $other->amount_minor && (int) $lines[0]->credit_minor === 0
                && $lines[1]->account_code === 'AR' && (int) $lines[1]->credit_minor === (int) $other->amount_minor
                && (int) $lines[1]->debit_minor === 0, 409, 'Previous allocation journal mismatch.');
        }
        return app(FinancialJournalPostingService::class)->post(
            $hotelId, 'no-show-allocation-'.$allocationId, 'no_show_deposit_allocation',
            'folio-allocation-'.$allocationId, 'Apply guest deposit to no-show receivable', [
                ['account_code' => 'guest_deposit_liability', 'debit_minor' => $amount, 'credit_minor' => 0],
                ['account_code' => 'AR', 'debit_minor' => 0, 'credit_minor' => $amount],
            ], $actorId
        );
    }
}

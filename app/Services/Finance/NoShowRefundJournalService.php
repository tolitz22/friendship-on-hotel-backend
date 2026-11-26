<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\NoShowRefundEligibility;
use App\Domain\Reservations\NoShowWorkflow;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Records independently verified external refunds, not bank transfers. */
final class NoShowRefundJournalService
{
    public function post(Hotel $hotel, int $bookingId, int $folioId, int $evidenceId, int $actorId): int
    {
        $hotelId = (int) $hotel->id;
        abort_unless(DB::transactionLevel() > 0
            && config('financial_accounting.enable_guest_deposit_posting', false)
            && config('financial_accounting.enable_no_show_allocation_posting', false)
            && config('financial_accounting.enable_no_show_refund_posting', false)
            && config('financial_accounting.enable_verified_settlement', false)
            && NoShowWorkflow::enabled($hotelId), 409, 'Refund posting is not enabled.');
        $evidence = DB::table('folio_refund_disbursement_evidence')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->where('id', $evidenceId)->first();
        abort_unless($evidence !== null && $evidence->status === 'verified'
            && (int) $evidence->reviewed_by === $actorId && (int) $evidence->recorded_by !== $actorId, 409);
        $refund = DB::table('folio_refund_requests')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->where('id', $evidence->refund_request_id)->first();
        $amount = (int) $evidence->amount_minor;
        abort_unless($refund !== null && $refund->status === 'approved' && $amount > 0
            && (int) $refund->amount_minor === $amount
            && (int) $refund->payment_record_id === (int) $evidence->payment_record_id, 409);
        $review = app(NoShowRefundEligibility::class)->review($hotel, $bookingId);
        abort_unless($review['reconciliationValid'], 409, 'No-show reconciliation required.');
        $paymentId = (int) $evidence->payment_record_id;
        $payment = DB::table('folio_payment_records')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->where('id', $paymentId)->first();
        abort_unless($payment !== null && $payment->method === 'bank_transfer' && $payment->status === 'staff_confirmed', 409);
        $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotelId);
        $receipt = (clone $journals)->where('source_reference', 'folio-payment-'.$paymentId)
            ->whereIn('source_type', ['guest_deposit_receipt', 'guest_deposit_opening_reclassification'])->get();
        abort_unless($receipt->count() === 1, 409, 'Recognized deposit required.');
        $this->assertLines($hotelId, (int) $receipt->first()->id,
            $receipt->first()->source_type === 'guest_deposit_receipt' ? 'hotel_bank' : 'guest_deposit_opening_clearing',
            'guest_deposit_liability', (int) $payment->amount_minor);
        $used = 0;
        foreach (DB::table('folio_settlement_allocations')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->where('payment_record_id', $paymentId)->get() as $allocation) {
            $journal = (clone $journals)->where('idempotency_key', 'no-show-allocation-'.$allocation->id)
                ->where('source_type', 'no_show_deposit_allocation')->where('source_reference', 'folio-allocation-'.$allocation->id)->first();
            abort_unless($journal !== null, 409, 'Allocation journal reconciliation required.');
            $this->assertLines($hotelId, (int) $journal->id, 'guest_deposit_liability', 'AR', (int) $allocation->amount_minor);
            $used += (int) $allocation->amount_minor;
        }
        foreach (DB::table('folio_refund_disbursement_evidence')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->where('payment_record_id', $paymentId)
            ->where('status', 'verified')->where('id', '!=', $evidenceId)->get() as $prior) {
            $journal = (clone $journals)->where('idempotency_key', 'no-show-refund-'.$prior->id)
                ->where('source_type', 'no_show_deposit_refund')->where('source_reference', 'refund-evidence-'.$prior->id)->first();
            abort_unless($journal !== null, 409, 'Previous refund journal reconciliation required.');
            $this->assertLines($hotelId, (int) $journal->id, 'guest_deposit_liability', 'hotel_bank', (int) $prior->amount_minor);
            $used += (int) $prior->amount_minor;
        }
        abort_unless($used >= 0 && $amount <= (int) $payment->amount_minor - $used, 409, 'Insufficient deposit liability.');
        $existing = (clone $journals)->where('idempotency_key', 'no-show-refund-'.$evidenceId)->first();
        if ($existing !== null) {
            $this->assertLines($hotelId, (int) $existing->id, 'guest_deposit_liability', 'hotel_bank', $amount);
        }
        return app(FinancialJournalPostingService::class)->post(
            $hotelId, 'no-show-refund-'.$evidenceId, 'no_show_deposit_refund',
            'refund-evidence-'.$evidenceId, 'Verified external no-show deposit refund', [
                ['account_code' => 'guest_deposit_liability', 'debit_minor' => $amount, 'credit_minor' => 0],
                ['account_code' => 'hotel_bank', 'debit_minor' => 0, 'credit_minor' => $amount],
            ], $actorId
        );
    }

    private function assertLines(int $hotelId, int $journalId, string $debit, string $credit, int $amount): void
    {
        abort_if(DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
            ->where('reverses_journal_id', $journalId)->exists(), 409, 'Related journal reversed.');
        $lines = DB::table('financial_posting_lines')->where('journal_id', $journalId)->orderBy('line_number')->get();
        abort_unless($amount > 0 && $lines->count() === 2
            && $lines[0]->account_code === $debit && (int) $lines[0]->debit_minor === $amount && (int) $lines[0]->credit_minor === 0
            && $lines[1]->account_code === $credit && (int) $lines[1]->credit_minor === $amount && (int) $lines[1]->debit_minor === 0,
            409, 'Related journal amount or account mismatch.');
    }
}

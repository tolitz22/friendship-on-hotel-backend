<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\NoShowWorkflow;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use App\Services\Finance\GuestDepositJournalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NoShowDepositController
{
    public function show(Request $request, Hotel $hotel, string $booking): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel, $booking): JsonResponse {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorizeActor($request, $hotel);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            abort_unless(DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $booking)->where('lifecycle_state', 'no_show')->exists(), 404);
            $folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $booking)->first();
            abort_unless($folio !== null && $folio->currency === 'PHP', 409, 'PHP folio required.');
            $enabled = config('financial_accounting.enable_guest_deposit_posting', false)
                && config('financial_accounting.enable_verified_settlement', false)
                && NoShowWorkflow::enabled((int) $hotel->id);
            $date = $clock?->business_date;
            $open = $clock !== null && $clock->status === 'open'
                && !DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->where('business_date', $date)->exists()
                && DB::table('financial_accounting_periods')->where('hotel_id', $hotel->id)->where('status', 'open')
                    ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->count() === 1;
            $hasSettlement = false;
            foreach (['folio_settlement_allocations', 'folio_refund_requests', 'folio_settlement_signoffs'] as $table) {
                $hasSettlement = $hasSettlement || DB::table($table)->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)->exists();
            }
            $items = [];
            foreach (DB::table('folio_payment_records')->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)->orderBy('id')->get() as $payment) {
                $verification = DB::table('folio_settlement_verifications')->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->where('payment_record_id', $payment->id)->first();
                $verified = $verification !== null && $verification->status === 'verified'
                    && $verification->evidence_kind === ($payment->method === 'cash' ? 'cash_shift' : 'bank_statement')
                    && (int) $verification->reviewed_by > 0
                    && (int) $verification->reviewed_by !== (int) $verification->prepared_by
                    && (int) $verification->reviewed_by !== (int) $payment->recorded_by
                    && $payment->status === 'staff_confirmed' && (int) $payment->amount_minor > 0;
                $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                    ->whereIn('source_reference', ['folio-payment-'.$payment->id, 'opening-payment-'.$payment->id])->get();
                $recognized = $journals->filter(fn ($j) => in_array($j->source_type, ['guest_deposit_receipt', 'guest_deposit_opening_reclassification'], true));
                $journal = $recognized->count() === 1 ? $recognized->first() : null;
                $status = 'ready';
                $reason = 'Record the existing verified deposit in accounting. No money is collected.';
                if ($journals->isNotEmpty()) {
                    $valid = $journal !== null && $journals->count() === 1 && $verified
                        && !DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('reverses_journal_id', $journal->id)->exists();
                    if ($valid) {
                        $lines = DB::table('financial_posting_lines')->where('journal_id', $journal->id)->orderBy('line_number')->get();
                        $debit = $journal->source_type === 'guest_deposit_opening_reclassification' ? 'guest_deposit_opening_clearing' : ($payment->method === 'cash' ? 'cashier_cash' : 'hotel_bank');
                        $valid = $journal->currency === 'PHP' && $lines->count() === 2
                            && $lines[0]->account_code === $debit && (int) $lines[0]->debit_minor === (int) $payment->amount_minor && (int) $lines[0]->credit_minor === 0
                            && $lines[1]->account_code === 'guest_deposit_liability' && (int) $lines[1]->credit_minor === (int) $payment->amount_minor && (int) $lines[1]->debit_minor === 0;
                    }
                    $status = $valid ? 'recognized' : 'reconcile';
                    $reason = $valid ? 'Deposit accounting entry recorded.' : 'Related accounting entries need reconciliation; do not post again.';
                } elseif (!$verified || !in_array($payment->method, ['bank_transfer', 'cash'], true)) {
                    $status = 'blocked'; $reason = 'Independently verified cash or bank deposit required.';
                } elseif ($date !== null && CarbonImmutable::parse($payment->created_at, config('app.timezone', 'UTC'))->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString() !== (string) $date) {
                    $status = 'historical'; $reason = 'Historical deposit requires a separate approved opening-balance review.';
                } elseif (!$enabled || !$open || $hasSettlement) {
                    $status = 'blocked'; $reason = !$enabled ? 'Deposit posting is not enabled.' : (!$open ? 'Open business date and accounting period required.' : 'Existing settlement requires financial reconciliation.');
                } elseif ($payment->method === 'cash') {
                    $status = 'blocked'; $reason = 'Cash recognition requires a separate cashier reconciliation check; use the authorized finance workflow.';
                }
                $items[] = ['paymentId' => (string) $payment->id, 'method' => $payment->method,
                    'amountMinor' => (int) $payment->amount_minor, 'verified' => $verified, 'status' => $status,
                    'reason' => $reason, 'journalId' => $journal === null ? null : (string) $journal->id,
                    'canRecognize' => $status === 'ready'];
            }
            return response()->json(['data' => ['bookingId' => $booking, 'currency' => 'PHP', 'businessDate' => $date,
                'postingEnabled' => $enabled, 'payments' => $items]])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function recognize(Request $request, Hotel $hotel, string $booking, string $payment, GuestDepositJournalService $service): JsonResponse
    {
        $this->authorizeActor($request, $hotel);
        $request->validate(['acknowledged' => ['required', 'accepted']]);
        $id = $service->recognize($hotel, (int) $booking, (int) $payment, (int) $request->user()->id);
        return response()->json(['data' => ['journalId' => (string) $id]]);
    }

    private function authorizeActor(Request $request, Hotel $hotel): void
    {
        $actor = $request->user()->id;
        abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)->where('user_id', $actor)
            ->where('role', 'admin')->where('is_active', true)->exists()
            && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
    }
}

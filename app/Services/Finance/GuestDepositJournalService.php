<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\NoShowWorkflow;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staged internal bank-deposit posting. No automatic caller or historical
 * backfill: finance must reconcile existing bank receipts before activation.
 */
final class GuestDepositJournalService
{
    public function recognize(Hotel $hotel, int $bookingId, int $paymentId, int $actorId): int
    {
        abort_unless(config('financial_accounting.enable_guest_deposit_posting', false)
            && config('financial_accounting.enable_verified_settlement', false)
            && NoShowWorkflow::enabled((int) $hotel->id), 409, 'Guest deposit posting is not enabled.');

        return DB::transaction(function () use ($hotel, $bookingId, $paymentId, $actorId): int {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)
                ->lockForUpdate()->first();
            abort_unless($clock !== null && $clock->status === 'open', 409, 'Open business date required.');
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
                ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
            $folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->lockForUpdate()->first();
            abort_unless($folio !== null && $folio->currency === 'PHP', 409, 'PHP folio required.');
            abort_unless(DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->where('lifecycle_state', 'no_show')->exists(), 409, 'No-show booking required.');
            $payment = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('id', $paymentId)->lockForUpdate()->first();
            abort_unless($payment !== null, 404);
            $verification = DB::table('folio_settlement_verifications')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('payment_record_id', $paymentId)->lockForUpdate()->first();
            abort_unless($payment->method === 'bank_transfer' && $payment->status === 'staff_confirmed'
                && (int) $payment->amount_minor > 0 && $verification !== null
                && $verification->status === 'verified' && $verification->evidence_kind === 'bank_statement'
                && (int) $verification->reviewed_by > 0
                && (int) $verification->reviewed_by !== (int) $verification->prepared_by
                && (int) $verification->reviewed_by !== (int) $payment->recorded_by, 409,
                'Independently verified bank deposit required.');
            $key = 'guest-deposit-payment-'.$paymentId;
            $existing = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                ->where('idempotency_key', $key)->first();
            if ($existing === null) {
                // Never automatically recognize a historical receipt on a later date.
                abort_unless(CarbonImmutable::parse($payment->created_at, config('app.timezone', 'UTC'))
                    ->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString()
                    === CarbonImmutable::parse($clock->business_date)->toDateString(), 409,
                    'Historical deposit requires a separate reconciled opening-balance workflow.');
                foreach (['folio_settlement_allocations', 'folio_refund_requests', 'folio_settlement_signoffs'] as $table) {
                    abort_if(DB::table($table)->where('hotel_id', $hotel->id)
                        ->where('folio_id', $folio->id)->exists(), 409, 'Existing settlement requires financial reconciliation.');
                }
            } else {
                abort_if(DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                    ->where('reverses_journal_id', $existing->id)->exists(), 409, 'Deposit journal was reversed.');
            }
            $amount = (int) $payment->amount_minor;
            return app(FinancialJournalPostingService::class)->post(
                (int) $hotel->id, $key, 'guest_deposit_receipt', 'folio-payment-'.$paymentId,
                'Verified guest bank deposit', [
                    ['account_code' => 'hotel_bank', 'debit_minor' => $amount, 'credit_minor' => 0],
                    ['account_code' => 'guest_deposit_liability', 'debit_minor' => 0, 'credit_minor' => $amount],
                ], $actorId
            );
        }, 3);
    }
}

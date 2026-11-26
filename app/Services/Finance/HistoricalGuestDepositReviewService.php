<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only review of legacy receipts. Never infers an opening balance or posts cash. */
final class HistoricalGuestDepositReviewService
{
    public function review(Hotel $hotel, int $bookingId, int $actorId): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actorId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
                ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)
                ->lockForUpdate()->first();
            abort_unless($clock !== null, 409, 'Financial business date required.');
            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->first();
            abort_unless($booking !== null, 404);
            abort_unless($booking->lifecycle_state === 'no_show', 409, 'No-show booking required.');
            $folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->first();
            abort_unless($folio !== null && $folio->currency === 'PHP', 409, 'PHP folio required.');
            $date = CarbonImmutable::parse($clock->business_date)->toDateString();
            $payments = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->orderBy('id')->get();
            $rows = [];
            foreach ($payments as $payment) {
                $receiptDate = CarbonImmutable::parse($payment->created_at, config('app.timezone', 'UTC'))
                    ->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString();
                if ($receiptDate >= $date) {
                    continue;
                }
                $verification = DB::table('folio_settlement_verifications')->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->where('payment_record_id', $payment->id)->first();
                $independent = $verification !== null && $verification->status === 'verified'
                    && (int) $verification->reviewed_by > 0
                    && (int) $verification->reviewed_by !== (int) $verification->prepared_by
                    && (int) $verification->reviewed_by !== (int) $payment->recorded_by
                    && (($payment->method === 'bank_transfer' && $verification->evidence_kind === 'bank_statement')
                        || ($payment->method === 'cash' && $verification->evidence_kind === 'cash_shift'));
                $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                    ->where(function ($query) use ($payment): void {
                        $query->where('idempotency_key', 'guest-deposit-payment-'.$payment->id)
                            ->orWhere('source_reference', 'folio-payment-'.$payment->id);
                    })->orderBy('id')->get();
                $allocated = (int) DB::table('folio_settlement_allocations')->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->where('payment_record_id', $payment->id)->sum('amount_minor');
                $refunds = (int) DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->where('payment_record_id', $payment->id)
                    ->whereIn('status', ['requested', 'approved'])->sum('amount_minor');
                $amount = (int) $payment->amount_minor;
                $status = match (true) {
                    $payment->status !== 'staff_confirmed' || !$independent || $amount <= 0 => 'evidence_review_required',
                    $journals->isNotEmpty() => 'existing_journal_review_required',
                    $allocated !== 0 || $refunds !== 0 => 'settlement_history_review_required',
                    default => 'opening_balance_review_required',
                };
                $rows[] = [
                    'paymentId' => (string) $payment->id, 'recordedDate' => $receiptDate,
                    'method' => $payment->method, 'recordedMinor' => $amount,
                    'independentlyVerified' => $independent, 'allocatedMinor' => $allocated,
                    'reservedRefundsMinor' => $refunds, 'reviewStatus' => $status,
                    'relatedJournalIds' => $journals->pluck('id')->map(fn ($id) => (string) $id)->all(),
                ];
            }
            return [
                'bookingId' => (string) $bookingId, 'businessDate' => $date, 'currency' => 'PHP',
                'historicalPayments' => $rows, 'previewOnly' => true, 'canPost' => false,
                'openingBalanceMinor' => null,
            ];
        }, 3);
    }
}

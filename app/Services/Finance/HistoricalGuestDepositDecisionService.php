<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\NoShowWorkflow;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Documents opening-balance evidence only; approval never posts a journal. */
final class HistoricalGuestDepositDecisionService
{
    public function propose(Hotel $hotel, int $bookingId, int $paymentId, int $actorId, string $reference, string $reason): int
    {
        $this->assertEnabled($hotel);
        $reference = trim($reference);
        $reason = trim($reason);
        abort_unless(mb_strlen($reference) >= 8 && mb_strlen($reference) <= 160
            && mb_strlen($reason) >= 12 && mb_strlen($reason) <= 1000, 422, 'Document the opening balance evidence.');

        return DB::transaction(function () use ($hotel, $bookingId, $paymentId, $actorId, $reference, $reason): int {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            [$date, $snapshot] = $this->snapshot($hotel, $bookingId, $paymentId, $actorId);
            abort_if(DB::table('historical_guest_deposit_decisions')->where('hotel_id', $hotel->id)
                ->where('payment_id', $paymentId)->exists(), 409, 'A decision already exists.');
            return (int) DB::table('historical_guest_deposit_decisions')->insertGetId([
                'hotel_id' => $hotel->id, 'booking_id' => $bookingId, 'payment_id' => $paymentId,
                'proposed_by' => $actorId, 'amount_minor' => $snapshot['recordedMinor'],
                'business_date' => $date, 'evidence_reference' => $reference, 'reason' => $reason,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);
        }, 3);
    }

    public function approve(Hotel $hotel, int $decisionId, int $actorId, string $reason): int
    {
        $this->assertEnabled($hotel);
        $reason = trim($reason);
        abort_unless(mb_strlen($reason) >= 12 && mb_strlen($reason) <= 1000, 422, 'Document independent review.');
        return DB::transaction(function () use ($hotel, $decisionId, $actorId, $reason): int {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $decision = DB::table('historical_guest_deposit_decisions')->where('hotel_id', $hotel->id)
                ->where('id', $decisionId)->lockForUpdate()->first();
            abort_unless($decision !== null, 404);
            abort_if((int) $decision->proposed_by === $actorId, 403, 'Independent reviewer required.');
            [$date, $snapshot] = $this->snapshot($hotel, (int) $decision->booking_id, (int) $decision->payment_id, $actorId);
            abort_unless((string) $decision->business_date === $date
                && (int) $decision->amount_minor === $snapshot['recordedMinor']
                && hash_equals($decision->snapshot_sha256, hash('sha256', $decision->snapshot))
                && hash_equals($decision->snapshot_sha256, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))),
                409, 'Opening balance evidence changed; separate correction required.');
            abort_if(DB::table('historical_guest_deposit_approvals')->where('decision_id', $decisionId)->exists(),
                409, 'Decision already approved.');
            return (int) DB::table('historical_guest_deposit_approvals')->insertGetId([
                'decision_id' => $decisionId, 'reviewed_by' => $actorId, 'reason' => $reason, 'created_at' => now(),
            ]);
        }, 3);
    }


    /**
     * Reclassify a documented opening-balance clearing credit, never cash.
     * The referenced opening journal must already contain the exact liability
     * clearing credit for this payment and must not have been reversed.
     */
    public function postOpeningBalance(Hotel $hotel, int $decisionId, int $openingJournalId, int $actorId): int
    {
        $this->assertEnabled($hotel);
        abort_unless(config('financial_accounting.enable_historical_guest_deposit_posting', false), 409,
            'Historical opening balance posting is disabled.');

        return DB::transaction(function () use ($hotel, $decisionId, $openingJournalId, $actorId): int {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
                ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            abort_unless($clock !== null && $clock->status === 'open', 409, 'Open business date required.');
            $decision = DB::table('historical_guest_deposit_decisions')->where('hotel_id', $hotel->id)
                ->where('id', $decisionId)->lockForUpdate()->first();
            abort_unless($decision !== null, 404);
            $approval = DB::table('historical_guest_deposit_approvals')->where('decision_id', $decisionId)->first();
            abort_unless($approval !== null && (int) $approval->reviewed_by !== (int) $decision->proposed_by
                && (int) $approval->reviewed_by === $actorId, 403, 'Independent approving administrator required.');
            abort_unless($decision->evidence_reference === 'opening-journal-'.$openingJournalId, 409,
                'The opening journal must be explicitly identified in the approved evidence.');
            $amount = (int) $decision->amount_minor;
            abort_unless($amount > 0 && hash_equals($decision->snapshot_sha256, hash('sha256', $decision->snapshot)), 409,
                'Invalid approved snapshot.');
            $source = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                ->where('id', $openingJournalId)->first();
            abort_unless($source !== null && $source->currency === 'PHP'
                && $source->source_type === 'guest_deposit_opening_import'
                && $source->source_reference === 'opening-payment-'.$decision->payment_id
                && $source->reverses_journal_id === null, 409, 'Matching imported opening journal required.');
            $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id);
            abort_if((clone $journals)->where('reverses_journal_id', $openingJournalId)->exists(), 409, 'Opening journal reversed.');
            $lines = DB::table('financial_posting_lines')->where('journal_id', $openingJournalId)
                ->where('account_code', 'guest_deposit_opening_clearing')->get();
            abort_unless($lines->count() === 1 && (int) $lines->first()->credit_minor === $amount
                && (int) $lines->first()->debit_minor === 0, 409, 'Opening clearing credit does not match approved deposit.');
            $key = 'guest-deposit-opening-'.$decision->payment_id;
            $existing = (clone $journals)->where('idempotency_key', $key)->first();
            if ($existing === null) {
                [$date, $snapshot] = $this->snapshot($hotel, (int) $decision->booking_id, (int) $decision->payment_id, $actorId);
                abort_unless((string) $decision->business_date === $date
                    && hash_equals($decision->snapshot_sha256, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))),
                    409, 'Approved evidence changed.');
            } else {
                abort_if((clone $journals)->where('reverses_journal_id', $existing->id)->exists(), 409, 'Opening reclassification reversed.');
            }
            return app(FinancialJournalPostingService::class)->post(
                (int) $hotel->id, $key, 'guest_deposit_opening_reclassification',
                'folio-payment-'.$decision->payment_id, 'Approved historical deposit reclassification', [
                    ['account_code' => 'guest_deposit_opening_clearing', 'debit_minor' => $amount, 'credit_minor' => 0],
                    ['account_code' => 'guest_deposit_liability', 'debit_minor' => 0, 'credit_minor' => $amount],
                ], $actorId
            );
        }, 3);
    }

    private function assertEnabled(Hotel $hotel): void
    {
        abort_unless(config('financial_accounting.enable_guest_deposit_posting', false)
            && config('financial_accounting.enable_verified_settlement', false)
            && NoShowWorkflow::enabled((int) $hotel->id), 409, 'Historical deposit decisions are not enabled.');
    }

    private function snapshot(Hotel $hotel, int $bookingId, int $paymentId, int $actorId): array
    {
        $review = app(HistoricalGuestDepositReviewService::class)->review($hotel, $bookingId, $actorId);
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->first();
        abort_unless($clock !== null && $clock->status === 'open'
            && !DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                ->where('business_date', $review['businessDate'])->exists(), 409, 'Open business date required.');
        foreach ($review['historicalPayments'] as $row) {
            if ($row['paymentId'] !== (string) $paymentId) {
                continue;
            }
            abort_unless($row['reviewStatus'] === 'opening_balance_review_required'
                && $row['method'] === 'bank_transfer', 409, 'Deposit requires separate financial review.');
            $payment = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)->where('id', $paymentId)->first();
            $verification = DB::table('folio_settlement_verifications')->where('hotel_id', $hotel->id)
                ->where('payment_record_id', $paymentId)->first();
            abort_unless($payment !== null && $verification !== null, 409);
            $row['paymentReference'] = $payment->reference;
            $row['verificationId'] = (string) $verification->id;
            $row['verificationReference'] = $verification->evidence_reference;
            $row['verificationReviewer'] = (string) $verification->reviewed_by;
            return [$review['businessDate'], $row];
        }
        abort(404, 'Historical payment not found.');
    }
}

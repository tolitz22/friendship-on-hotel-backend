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

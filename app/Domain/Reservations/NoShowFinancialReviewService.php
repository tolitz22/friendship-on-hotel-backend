<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NoShowFinancialReviewService
{
    /** Read-only proposal. Never posts a fee, waives a charge or refunds money. */
    public function preview(Hotel $hotel, int $bookingId): array
    {
        return DB::transaction(function () use ($hotel, $bookingId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking !== null, 404);
            abort_unless($booking->lifecycle_state === 'no_show', 409, 'Record the no-show before reviewing its finances.');

            $accounting = app(FolioAccountingService::class);
            $folio = $accounting->findFolio($hotel, $bookingId, true);
            $summary = $accounting->summaryFor($folio);
            $snapshot = DB::table('booking_pricing_snapshots')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->first();
            $terms = $snapshot === null ? null : (json_decode($snapshot->snapshot, true, 512, JSON_THROW_ON_ERROR)['policyTerms'] ?? null);
            $nights = ActiveFolioEntryScope::apply(DB::table('folio_entries'))
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->where('entry_type', 'room_night')->orderBy('stay_date')->get();
            $subtotal = (int) $nights->sum('amount_minor');
            $firstNight = (int) ($nights->first()->amount_minor ?? 0);
            $kind = $terms['noShowKind'] ?? null;
            $value = $terms['noShowValue'] ?? null;
            $blockers = [];
            $fee = null;
            if (!is_int($value) || $value < 0 || $subtotal < 0 || $nights->isEmpty()
                || ($kind === 'percent' && $value > 10000)) {
                $blockers[] = 'No valid recorded no-show policy. Manual policy review required.';
            } else {
                $fee = match ($kind) {
                    'none' => 0,
                    'fixed' => min($subtotal, $value),
                    'first_night' => $firstNight,
                    'full' => $subtotal,
                    'percent' => intdiv($subtotal, 10000) * $value + intdiv(($subtotal % 10000) * $value + 5000, 10000),
                    default => null,
                };
                if ($fee === null) {
                    $blockers[] = 'Unsupported recorded no-show policy.';
                }
            }
            if (Schema::hasTable('reservation_pricing_revisions') && DB::table('reservation_pricing_revisions')
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)->exists()) {
                $fee = null;
                $blockers[] = 'Revised stay policy requires manual review.';
            }
            foreach (['financial_folio_night_assessments', 'folio_settlement_signoffs', 'folio_settlement_allocations', 'folio_refund_requests'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->exists()) {
                    $blockers[] = 'Existing accounting or settlement activity requires reviewed financial correction.';
                    break;
                }
            }
            if (DB::table('folio_adjustments')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->exists()) {
                $blockers[] = 'Existing folio adjustments require manual financial review.';
            }
            if (Schema::hasTable('financial_business_clocks')) {
                $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->first();
                if ($clock !== null && $booking->check_in < $clock->business_date) {
                    $blockers[] = 'Past business dates require an audited accounting correction.';
                }
            }
            if (Schema::hasTable('financial_day_closes') && DB::table('financial_day_closes')
                ->where('hotel_id', $hotel->id)->where('status', 'completed')
                ->where('business_date', '>=', $booking->check_in)
                ->where('business_date', '<', $booking->check_out)->exists()) {
                $blockers[] = 'A closed financial period requires an audited accounting correction.';
            }
            $prior = Schema::hasTable('no_show_financial_reviews')
                ? DB::table('no_show_financial_reviews')->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->first() : null;
            if ($prior !== null) {
                $blockers[] = 'No-show financial adjustments have already been recorded.';
            }
            $review = [
                'bookingId' => (string) $bookingId, 'currency' => $folio->currency,
                'policyVersion' => $terms['version'] ?? null, 'noShowKind' => $kind,
                'originalRoomSubtotalMinor' => $subtotal, 'proposedNoShowFeeMinor' => $fee,
                'proposedRoomCreditMinor' => $fee === null ? null : $subtotal - $fee,
                'currentFolioSummary' => $summary, 'blockers' => $blockers,
                'previewOnly' => true, 'applied' => false,
                'canApply' => config('booking.no_show_adjustments_enabled', false)
                    && Schema::hasTable('no_show_financial_reviews') && $blockers === [] && $fee !== null,
                'recordedReviewId' => $prior === null ? null : (string) $prior->id,
            ];
            $review['reviewFingerprint'] = hash_hmac('sha256', json_encode([
                'hotelId' => (string) $hotel->id, 'folioId' => (string) $folio->id,
                'bookingVersion' => (string) $booking->updated_at,
                'entryIds' => $nights->pluck('id')->map(fn ($id) => (string) $id)->all(),
                'review' => $review,
            ], JSON_THROW_ON_ERROR), (string) config('app.key'));
            return $review;
        }, 3);
    }
    /** Internal write path; do not expose until fee accounting and close guards are integrated. */
    public function apply(Hotel $hotel, int $bookingId, int $actor, string $fingerprint, string $reason): array
    {
        abort_unless(config('booking.no_show_adjustments_enabled', false), 409, 'No-show adjustments are not enabled.');
        abort_if(mb_strlen(trim($reason)) < 12 || mb_strlen($reason) > 500, 422, 'A review reason is required.');
        return DB::transaction(function () use ($hotel, $bookingId, $actor, $fingerprint, $reason): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
            app(FolioAccountingService::class)->assertNotSigned($hotel, $folio);
            if (Schema::hasTable('financial_business_clocks')) {
                DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            }
            $review = $this->preview($hotel, $bookingId);
            abort_unless($review['canApply'] && hash_equals($review['reviewFingerprint'], $fingerprint), 409, 'Financial review changed or requires manual correction.');
            $subtotal = $review['originalRoomSubtotalMinor'];
            $fee = $review['proposedNoShowFeeMinor'];
            abort_unless(is_int($subtotal) && is_int($fee) && $fee >= 0 && $fee <= $subtotal, 409, 'Invalid reviewed fee.');
            $creditId = null;
            $feeId = null;
            foreach ([['credit', $subtotal, 'Reverse unconsumed room charges'], ['debit', $fee, 'Reviewed no-show fee']] as [$kind, $amount, $description]) {
                if ($amount === 0) continue;
                $id = DB::table('folio_adjustments')->insertGetId([
                    'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                    'request_key' => 'no-show-'.$bookingId.'-'.$kind,
                    'payload_sha256' => hash('sha256', $fingerprint.':'.$kind.':'.$amount),
                    'kind' => $kind, 'amount_minor' => $amount, 'reason' => $description,
                    'actor_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($kind === 'credit') $creditId = $id;
                else $feeId = $id;
            }
            $id = DB::table('no_show_financial_reviews')->insertGetId([
                'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId, 'folio_id' => $folio->id,
                'actor_id' => $actor, 'review_fingerprint' => $fingerprint, 'currency' => $folio->currency,
                'room_subtotal_minor' => $subtotal, 'fee_minor' => $fee, 'credit_minor' => $subtotal,
                'credit_adjustment_id' => $creditId, 'fee_adjustment_id' => $feeId,
                'review_snapshot' => json_encode($review, JSON_THROW_ON_ERROR),
                'reason' => trim($reason), 'created_at' => now(),
            ]);
            return ['reviewId' => (string) $id, 'applied' => true, 'refundIssued' => false,
                'summary' => app(FolioAccountingService::class)->summaryFor($folio)];
        }, 3);
    }

}

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
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking !== null, 404);
            abort_unless($booking->lifecycle_state === 'no_show', 409, 'Record the no-show before reviewing its finances.');

            $accounting = app(FolioAccountingService::class);
            $folio = $accounting->findFolio($hotel, $bookingId, true);
            $summary = $accounting->summaryFor($folio);
            $snapshot = DB::table('booking_pricing_snapshots')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->first();
            $terms = $snapshot === null ? null : (json_decode($snapshot->snapshot, true)['policyTerms'] ?? null);
            $nights = ActiveFolioEntryScope::apply(DB::table('folio_entries'))
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->where('entry_type', 'room_night')->orderBy('stay_date')->get();
            $revision = DB::table('reservation_pricing_revisions')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('reservation_record_id', $bookingId)->latest('id')->first();
            $revisionNights = $revision === null ? collect() : DB::table('reservation_pricing_revision_nights')
                ->where('revision_id', $revision->id)->where('kind', 'replacement')->orderBy('stay_date')->get();
            $effectiveNights = $revision === null ? $nights : $revisionNights;
            $subtotal = (int) $effectiveNights->sum('amount_minor');
            $firstNight = (int) ($effectiveNights->first()->amount_minor ?? 0);
            $kind = $terms['noShowKind'] ?? null;
            $kind = is_string($kind) ? $kind : null;
            $value = $terms['noShowValue'] ?? null;
            $blockers = [];
            $fee = null;
            if (is_int($value) && $value >= 0) {
                $fee = match ($kind) {
                    'none' => 0,
                    'fixed' => min($subtotal, $value),
                    'first_night' => $firstNight,
                    'full' => $subtotal,
                    'percent' => $value <= 10000
                        ? intdiv($subtotal, 10000) * $value + intdiv(($subtotal % 10000) * $value + 5000, 10000)
                        : null,
                    default => null,
                };
            }
            if ($effectiveNights->isEmpty() || $subtotal < 0 || $subtotal > 1000000000000
                || $subtotal !== $summary['roomSubtotalMinor']) {
                $blockers[] = 'The active room charges must reconcile before applying a no-show adjustment.';
            }
            if ($clock === null || $clock->status !== 'open' || $folio->currency !== 'PHP'
                || DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                    ->where('business_date', $clock->business_date)->exists()) {
                $blockers[] = 'An open PHP business date is required to post the reconciliation.';
            }
            $prior = Schema::hasTable('no_show_financial_reviews')
                ? DB::table('no_show_financial_reviews')->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->first() : null;
            if ($prior !== null) {
                $blockers[] = 'No-show financial adjustments have already been recorded.';
            } elseif (($blocker = NoShowWorkflow::accountingBlocker((int) $hotel->id, (int) $folio->id)) !== null) {
                $blockers[] = $blocker;
            }

            $canReview = NoShowWorkflow::enabled((int) $hotel->id)
                && Schema::hasTable('no_show_financial_reviews') && $blockers === [];
            $clearance = NoShowRefundEligibility::clearance((int) $hotel->id, $bookingId, (int) $folio->id);
            $refund = $prior === null ? null : app(NoShowRefundEligibility::class)->review($hotel, $bookingId);
            $review = [
                'bookingId' => (string) $bookingId, 'currency' => $folio->currency,
                'policyVersion' => $terms['version'] ?? null, 'noShowKind' => $kind,
                'originalRoomSubtotalMinor' => $subtotal, 'proposedNoShowFeeMinor' => $fee,
                'proposedRoomCreditMinor' => $subtotal,
                'businessDate' => $clock?->business_date,
                'canApplyManual' => $canReview && $fee === null,
                'currentFolioSummary' => $summary, 'blockers' => $blockers,
                'previewOnly' => true, 'applied' => false,
                'canApply' => $canReview && $fee !== null,
                'recordedReviewId' => $prior === null ? null : (string) $prior->id,
                'recordedBy' => $prior === null ? null : (string) $prior->actor_id,
                'recordedFeeMinor' => $prior === null ? null : (int) $prior->fee_minor,
                'approvalEnabled' => (bool) NoShowWorkflow::enabled((int) $hotel->id),
                'refundEligibility' => $refund,
                'recordedReason' => $prior?->reason,
                'clearance' => $clearance === null ? null : [
                    'id' => (string) $clearance->id, 'vatMinor' => (int) $clearance->vat_minor,
                    'revenueMinor' => (int) $clearance->revenue_minor,
                    'businessDate' => (string) $clearance->business_date,
                ],
            ];
            $review['reviewFingerprint'] = hash_hmac('sha256', json_encode([
                'hotelId' => (string) $hotel->id, 'folioId' => (string) $folio->id,
                'bookingVersion' => (string) $booking->updated_at,
                'entries' => $effectiveNights->map(fn ($row) => [$row->id, $row->stay_date, (int) $row->amount_minor])->all(),
                'revisionId' => $revision?->id,
                'terms' => $terms,
                'paymentEvidence' => DB::table('folio_payment_records as p')
                    ->leftJoin('folio_settlement_verifications as v', function ($join): void {
                        $join->on('v.payment_record_id', '=', 'p.id')->on('v.hotel_id', '=', 'p.hotel_id');
                    })
                    ->where('p.hotel_id', $hotel->id)->where('p.folio_id', $folio->id)
                    ->orderBy('p.id')->get(['p.id', 'p.amount_minor', 'p.status', 'v.status as verification_status'])->all(),
                'review' => $review,
            ], JSON_THROW_ON_ERROR), (string) config('app.key'));
            return $review;
        }, 3);
    }
    public function apply(Hotel $hotel, int $bookingId, int $actor, string $fingerprint, string $reason, ?int $manualFee = null): array
    {
        abort_unless(NoShowWorkflow::enabled((int) $hotel->id), 409, 'No-show adjustments are not enabled.');
        abort_if(mb_strlen(trim($reason)) < 12 || mb_strlen($reason) > 500, 422, 'A review reason is required.');
        return DB::transaction(function () use ($hotel, $bookingId, $actor, $fingerprint, $reason, $manualFee): array {
            $review = $this->preview($hotel, $bookingId);
            $canApply = $manualFee === null ? $review['canApply'] : $review['canApplyManual'];
            abort_unless($canApply && hash_equals($review['reviewFingerprint'], $fingerprint), 409,
                'Financial review changed or requires a documented manual decision.');
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
            $subtotal = $review['originalRoomSubtotalMinor'];
            $fee = $manualFee ?? $review['proposedNoShowFeeMinor'];
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
                'review_snapshot' => json_encode([...$review, 'manualFeeMinor' => $manualFee], JSON_THROW_ON_ERROR),
                'reason' => trim($reason), 'created_at' => now(),
            ]);
            return ['reviewId' => (string) $id, 'applied' => true, 'refundIssued' => false,
                'summary' => app(FolioAccountingService::class)->summaryFor($folio)];
        }, 3);
    }

}

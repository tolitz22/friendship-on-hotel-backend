<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\FolioAccountingService;
use App\Domain\Reservations\FolioSettlementService;
use App\Domain\Reservations\NoShowRefundEligibility;
use App\Domain\Reservations\NoShowWorkflow;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Read-only eligibility for reduction or waiver of a cleared no-show fee. */
final class NoShowFeeReversalPreview
{
    public function preview(Hotel $hotel, int $bookingId, int $actorId, int $replacementMinor): array
    {
        abort_if($replacementMinor < 0, 422, 'Replacement fee cannot be negative.');

        return DB::transaction(function () use ($hotel, $bookingId, $actorId, $replacementMinor): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $hotelId = (int) $hotel->id;
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotelId)
                ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
            $booking = DB::table('reservation_records')->where('hotel_id', $hotelId)->where('id', $bookingId)
                ->lockForUpdate()->first();
            abort_unless($booking !== null, 404);
            abort_unless($booking->lifecycle_state === 'no_show', 409, 'Booking must remain a no-show.');
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
            $review = DB::table('no_show_financial_reviews')->where('hotel_id', $hotelId)
                ->where('reservation_record_id', $bookingId)->where('folio_id', $folio->id)->first();
            $clearance = NoShowRefundEligibility::clearance($hotelId, $bookingId, (int) $folio->id);
            $statement = app(FolioSettlementService::class)->statement($hotel, $bookingId);
            $gross = $clearance === null ? null : (int) $clearance->gross_minor;
            $blockers = [];
            if (!NoShowWorkflow::enabled($hotelId)) $blockers[] = 'no_show_workflow_disabled';
            if ($clock === null || $clock->status !== 'open' || DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                ->where('business_date', $clock->business_date)->exists()) $blockers[] = 'open_business_date_required';
            if ($folio->currency !== 'PHP') $blockers[] = 'unsupported_currency';
            if ($review === null || $gross === null || $gross <= 0 || $clearance->journal_id === null)
                $blockers[] = 'valid_positive_fee_clearance_required';
            if ($gross !== null && $replacementMinor >= $gross) $blockers[] = 'replacement_must_reduce_fee';
            if ($gross !== $statement['netRecordedChargesMinor']) $blockers[] = 'folio_total_mismatch';
            if ($statement['signed']) $blockers[] = 'signed_folio_requires_separate_correction';
            if (!$statement['paymentEvidenceIndependentlyReviewed']) $blockers[] = 'payment_evidence_requires_review';
            foreach (['folio_settlement_allocations', 'folio_refund_requests'] as $table) {
                if (DB::table($table)->where('hotel_id', $hotelId)->where('folio_id', $folio->id)->exists())
                    $blockers[] = $table.'_requires_separate_reconciliation';
            }
            if ($review !== null) {
                $expected = [];
                if ((int) $review->credit_minor > 0) $expected[(int) $review->credit_adjustment_id] = ['credit', (int) $review->credit_minor];
                if ((int) $review->fee_minor > 0) $expected[(int) $review->fee_adjustment_id] = ['debit', (int) $review->fee_minor];
                $adjustments = DB::table('folio_adjustments')->where('hotel_id', $hotelId)->where('folio_id', $folio->id)->get();
                if ($adjustments->count() !== count($expected)) $blockers[] = 'unexpected_folio_adjustments';
                foreach ($adjustments as $adjustment) {
                    if (($expected[(int) $adjustment->id] ?? null) !== [$adjustment->kind, (int) $adjustment->amount_minor])
                        $blockers[] = 'unexpected_folio_adjustments';
                }
            }
            $journal = $clearance?->journal_id === null ? null : DB::table('financial_posting_journals')
                ->where('hotel_id', $hotelId)->where('id', $clearance->journal_id)->first();
            $lines = $journal === null ? [] : DB::table('financial_posting_lines')->where('journal_id', $journal->id)
                ->orderBy('line_number')->get()->map(fn ($line): array => [
                    'account_code' => $line->account_code, 'debit_minor' => (int) $line->debit_minor,
                    'credit_minor' => (int) $line->credit_minor,
                ])->all();
            $expectedLines = $gross === null || $gross <= 0 ? [] : [
                ['account_code' => 'AR', 'debit_minor' => $gross, 'credit_minor' => 0],
            ];
            if ($clearance !== null && (int) $clearance->revenue_minor > 0)
                $expectedLines[] = ['account_code' => 'NO_SHOW_REV', 'debit_minor' => 0, 'credit_minor' => (int) $clearance->revenue_minor];
            if ($clearance !== null && (int) $clearance->vat_minor > 0)
                $expectedLines[] = ['account_code' => 'VAT_PAYABLE', 'debit_minor' => 0, 'credit_minor' => (int) $clearance->vat_minor];
            if ($journal === null || $journal->currency !== 'PHP' || $journal->reverses_journal_id !== null || $lines !== $expectedLines)
                $blockers[] = 'original_fee_journal_mismatch';
            $funds = $statement['verifiedPaymentsMinor'] - $statement['verifiedRefundsMinor'];
            if ($funds < 0 || $statement['verifiedAllocatedMinor'] !== 0 || $statement['verifiedRefundsMinor'] !== 0)
                $blockers[] = 'settlement_history_requires_review';
            $blockers = array_values(array_unique($blockers));

            return [
                'bookingId' => (string) $bookingId, 'currency' => $folio->currency, 'businessDate' => $clock?->business_date,
                'reviewId' => $review === null ? null : (string) $review->id,
                'clearanceId' => $clearance === null ? null : (string) $clearance->id,
                'originalFeeMinor' => $gross, 'replacementFeeMinor' => $replacementMinor,
                'proposedFolioCreditMinor' => $gross !== null && $replacementMinor < $gross ? $gross - $replacementMinor : null,
                'originalVatMinor' => $clearance === null ? null : (int) $clearance->vat_minor,
                'originalRevenueMinor' => $clearance === null ? null : (int) $clearance->revenue_minor,
                'replacementVatMinor' => null, 'replacementRevenueMinor' => null,
                'originalJournalId' => $journal === null ? null : (string) $journal->id,
                'originalJournalClosed' => $journal !== null && DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                    ->where('business_date', $journal->business_date)->exists(),
                'proposedReversalLines' => $lines === $expectedLines ? array_map(fn (array $line): array => [
                    'account_code' => $line['account_code'], 'debit_minor' => $line['credit_minor'], 'credit_minor' => $line['debit_minor'],
                ], $lines) : [],
                'verifiedFundsMinor' => $funds,
                'projectedCollectionMinor' => $blockers === [] ? max(0, $replacementMinor - $funds) : null,
                'projectedExcessDepositMinor' => $blockers === [] ? max(0, $funds - $replacementMinor) : null,
                'blockers' => $blockers, 'eligibleForReview' => $blockers === [],
                'previewOnly' => true, 'canPost' => false, 'refundIssued' => false,
            ];
        }, 3);
    }
}

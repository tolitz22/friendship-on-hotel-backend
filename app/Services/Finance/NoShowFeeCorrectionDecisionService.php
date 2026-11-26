<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Records correction intent and independent approval without posting any money. */
final class NoShowFeeCorrectionDecisionService
{
    public function propose(Hotel $hotel, int $bookingId, int $actorId, int $replacementMinor, string $reason): int
    {
        $this->enabled($hotel);
        $reason = $this->document($reason);
        return DB::transaction(function () use ($hotel, $bookingId, $actorId, $replacementMinor, $reason): int {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $snapshot = $this->snapshot($hotel, $bookingId, $actorId, $replacementMinor);
            $preview = $snapshot['preview'];
            abort_if(DB::table('no_show_fee_correction_proposals')->where('hotel_id', $hotel->id)
                ->where('clearance_id', $preview['clearanceId'])->exists(), 409, 'A fee correction proposal already exists.');
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
            return (int) DB::table('no_show_fee_correction_proposals')->insertGetId([
                'hotel_id' => $hotel->id, 'booking_id' => $bookingId, 'clearance_id' => $preview['clearanceId'],
                'proposed_by' => $actorId, 'original_fee_minor' => $preview['originalFeeMinor'],
                'replacement_fee_minor' => $replacementMinor, 'business_date' => $preview['businessDate'],
                'reason' => $reason, 'snapshot' => $json, 'snapshot_sha256' => hash('sha256', $json),
                'created_at' => now(),
            ]);
        }, 3);
    }

    public function approve(Hotel $hotel, int $proposalId, int $actorId, string $taxClassification, string $taxBasis, string $reason): int
    {
        $this->enabled($hotel);
        $reason = $this->document($reason);
        $taxBasis = $this->document($taxBasis);
        abort_unless(in_array($taxClassification, ['vat_standard', 'vat_zero', 'vat_exempt', 'non_vat'], true),
            422, 'A supported tax classification is required.');
        return DB::transaction(function () use ($hotel, $proposalId, $actorId, $taxClassification, $taxBasis, $reason): int {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $proposal = DB::table('no_show_fee_correction_proposals')->where('hotel_id', $hotel->id)
                ->where('id', $proposalId)->lockForUpdate()->first();
            abort_unless($proposal !== null, 404);
            abort_if((int) $proposal->proposed_by === $actorId, 403, 'Independent administrator required.');
            $snapshot = $this->snapshot($hotel, (int) $proposal->booking_id, $actorId, (int) $proposal->replacement_fee_minor);
            $preview = $snapshot['preview'];
            abort_unless((string) $proposal->clearance_id === $preview['clearanceId']
                && (int) $proposal->original_fee_minor === $preview['originalFeeMinor']
                && (string) $proposal->business_date === $preview['businessDate']
                && hash_equals($proposal->snapshot_sha256, hash('sha256', $proposal->snapshot))
                && hash_equals($proposal->snapshot_sha256, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))),
                409, 'Fee correction evidence changed; approval blocked.');
            abort_if(DB::table('no_show_fee_correction_reviews')->where('proposal_id', $proposalId)->exists(),
                409, 'Fee correction already approved.');
            $gross = (int) $proposal->replacement_fee_minor;
            $tax = app(PhilippineTaxCalculator::class)->calculate(
                $gross, $taxClassification, 'inclusive', $taxClassification === 'vat_standard' ? 1200 : 0
            );
            $vat = $tax['tax_minor'];
            abort_unless(is_int($vat) && $vat >= 0 && $vat <= $gross, 409, 'Invalid replacement tax calculation.');
            return (int) DB::table('no_show_fee_correction_reviews')->insertGetId([
                'proposal_id' => $proposalId, 'reviewed_by' => $actorId, 'tax_classification' => $taxClassification,
                'replacement_vat_minor' => $vat, 'replacement_revenue_minor' => $gross - $vat,
                'tax_basis' => $taxBasis, 'reason' => $reason, 'created_at' => now(),
            ]);
        }, 3);
    }


    /**
     * Internal primitive for the atomic fee correction workflow.
     * The caller must hold an outer transaction covering reversal,
     * replacement fee and folio credit. Never expose directly.
     */
    private function reverseApprovedFee(Hotel $hotel, int $proposalId, int $actorId): int
    {
        $this->enabled($hotel);
        abort_unless(config('financial_accounting.enable_no_show_fee_correction_posting', false),
            409, 'Fee correction posting is disabled.');
        abort_unless(DB::transactionLevel() > 0, 409, 'An atomic fee correction transaction is required.');

        app(ReadinessWriteGuard::class)->lockHotel($hotel);
        $hotelId = (int) $hotel->id;
        abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotelId)
            ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
            && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
        abort_unless($clock !== null && $clock->status === 'open'
            && !DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                ->where('business_date', $clock->business_date)->exists(), 409, 'Open business date required.');
        $proposal = DB::table('no_show_fee_correction_proposals')->where('hotel_id', $hotelId)
            ->where('id', $proposalId)->lockForUpdate()->first();
        abort_unless($proposal !== null, 404);
        $approval = DB::table('no_show_fee_correction_reviews')->where('proposal_id', $proposalId)->first();
        abort_unless($approval !== null && (int) $approval->reviewed_by === $actorId
            && (int) $proposal->proposed_by !== $actorId, 403, 'Independent approving administrator required.');
        abort_unless(hash_equals($proposal->snapshot_sha256, hash('sha256', $proposal->snapshot)),
            409, 'Invalid approved fee snapshot.');
        $approved = json_decode($proposal->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $preview = $approved['preview'];
        $gross = (int) $proposal->replacement_fee_minor;
        abort_unless($gross >= 0 && $gross < (int) $proposal->original_fee_minor
            && $gross === $preview['replacementFeeMinor']
            && (int) $proposal->original_fee_minor === $preview['originalFeeMinor']
            && (string) $proposal->clearance_id === $preview['clearanceId']
            && (string) $proposal->booking_id === $preview['bookingId']
            && in_array($approval->tax_classification, ['vat_standard', 'vat_zero', 'vat_exempt', 'non_vat'], true),
            409, 'Approved fee decision is inconsistent.');
        $tax = app(PhilippineTaxCalculator::class)->calculate(
            $gross, $approval->tax_classification, 'inclusive', $approval->tax_classification === 'vat_standard' ? 1200 : 0
        );
        abort_unless((int) $approval->replacement_vat_minor === $tax['tax_minor']
            && (int) $approval->replacement_revenue_minor === $gross - $tax['tax_minor'],
            409, 'Approved replacement tax calculation changed.');

        $originalId = (int) $preview['originalJournalId'];
        $key = 'no-show-fee-reversal-'.$proposalId;
        $existing = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
            ->where('idempotency_key', $key)->first();
        // Only the outer orchestration may replay the complete correction.
        abort_if($existing !== null, 409, 'Fee reversal already posted; verify the complete correction.');
        $snapshot = $this->snapshot($hotel, (int) $proposal->booking_id, $actorId, $gross);
        abort_unless((string) $proposal->business_date === (string) $clock->business_date
            && hash_equals($proposal->snapshot_sha256, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))),
            409, 'Fee correction evidence changed; reversal blocked.');
        $lines = $preview['proposedReversalLines'];
        abort_unless($originalId > 0 && count($lines) >= 2, 409, 'Verified original fee journal required.');

        return app(FinancialJournalPostingService::class)->post(
            $hotelId, $key, 'no_show_fee_reversal', 'proposal-'.$proposalId,
            'Approved no-show fee reversal #'.$proposalId, $lines, $actorId, $originalId
        );
    }


    /**
     * Staged atomic correction: reverse the old fee, post the replacement and
     * append the compensating folio credit and validate the effective clearance.
     */
    public function applyApprovedCorrection(Hotel $hotel, int $proposalId, int $actorId): array
    {
        return DB::transaction(function () use ($hotel, $proposalId, $actorId): array {
            $this->enabled($hotel);
            abort_unless(config('financial_accounting.enable_no_show_fee_correction_posting', false)
                && \App\Domain\Reservations\NoShowWorkflow::enabled((int) $hotel->id),
                409, 'Fee correction posting is disabled.');
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $hotelId = (int) $hotel->id;
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotelId)
                ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
            DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
            $proposal = DB::table('no_show_fee_correction_proposals')->where('hotel_id', $hotelId)
                ->where('id', $proposalId)->lockForUpdate()->first();
            abort_unless($proposal !== null, 404);
            $approval = DB::table('no_show_fee_correction_reviews')->where('proposal_id', $proposalId)->first();
            abort_unless($approval !== null && (int) $approval->reviewed_by === $actorId
                && (int) $proposal->proposed_by !== $actorId, 403, 'Independent approving administrator required.');
            $accounting = app(\App\Domain\Reservations\FolioAccountingService::class);
            $folio = $accounting->findFolio($hotel, (int) $proposal->booking_id, true);
            abort_unless(DB::table('reservation_records')->where('hotel_id', $hotelId)
                ->where('id', $proposal->booking_id)->where('lifecycle_state', 'no_show')->exists(),
                409, 'Booking must remain a no-show.');
            $validator = app(NoShowEffectiveFeeClearance::class);
            $reference = 'proposal-'.$proposalId;
            $hasJournal = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                ->where(function ($query) use ($reference, $proposalId): void {
                    $query->where(fn ($q) => $q->where('source_reference', $reference)
                        ->whereIn('source_type', ['no_show_fee_reversal', 'no_show_fee_replacement']))
                        ->orWhereIn('idempotency_key', ['no-show-fee-reversal-'.$proposalId, 'no-show-fee-replacement-'.$proposalId]);
                })->exists();
            $hasCredit = DB::table('folio_adjustments')->where('hotel_id', $hotelId)
                ->where('request_key', 'no-show-fee-correction-'.$proposalId)->exists();
            if ($hasJournal || $hasCredit) {
                $effective = $validator->resolve($hotelId, (int) $proposal->booking_id, (int) $folio->id, $proposal);
                abort_unless($effective !== null, 409, 'Incomplete or invalid fee correction requires reconciliation.');
                return $this->correctionResult($proposal, $effective, true);
            }
            $accounting->assertNotSigned($hotel, $folio);
            $reversalId = $this->reverseApprovedFee($hotel, $proposalId, $actorId);
            $gross = (int) $proposal->replacement_fee_minor;
            $credit = (int) $proposal->original_fee_minor - $gross;
            $vat = (int) $approval->replacement_vat_minor;
            $revenue = (int) $approval->replacement_revenue_minor;
            abort_unless($credit > 0 && $gross >= 0 && $vat >= 0 && $revenue >= 0
                && $vat + $revenue === $gross, 409, 'Invalid replacement amounts.');
            $replacementJournalId = null;
            if ($gross > 0) {
                $lines = [['account_code' => 'AR', 'debit_minor' => $gross, 'credit_minor' => 0]];
                if ($revenue > 0) $lines[] = ['account_code' => 'NO_SHOW_REV', 'debit_minor' => 0, 'credit_minor' => $revenue];
                if ($vat > 0) $lines[] = ['account_code' => 'VAT_PAYABLE', 'debit_minor' => 0, 'credit_minor' => $vat];
                $replacementJournalId = app(FinancialJournalPostingService::class)->post(
                    $hotelId, 'no-show-fee-replacement-'.$proposalId, 'no_show_fee_replacement',
                    'proposal-'.$proposalId, 'Approved replacement no-show fee #'.$proposalId, $lines, $actorId
                );
            }
            $adjustmentId = DB::table('folio_adjustments')->insertGetId([
                'hotel_id' => $hotelId, 'folio_id' => $folio->id,
                'request_key' => 'no-show-fee-correction-'.$proposalId,
                'payload_sha256' => hash('sha256', json_encode([$proposalId, $credit, $reversalId, $replacementJournalId], JSON_THROW_ON_ERROR)),
                'kind' => 'credit', 'amount_minor' => $credit,
                'reason' => 'Independently approved no-show fee correction #'.$proposalId,
                'actor_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $statement = app(\App\Domain\Reservations\FolioSettlementService::class)->statement($hotel, (int) $proposal->booking_id);
            $snapshot = json_decode($proposal->snapshot, true, 512, JSON_THROW_ON_ERROR);
            abort_unless($statement['netRecordedChargesMinor'] === $gross
                && $statement['verifiedPaymentsMinor'] === $snapshot['preview']['verifiedFundsMinor']
                && $statement['verifiedAllocatedMinor'] === 0 && $statement['verifiedRefundsMinor'] === 0,
                409, 'Corrected folio or deposit balance does not reconcile.');
            $effective = $validator->resolve($hotelId, (int) $proposal->booking_id, (int) $folio->id, $proposal);
            abort_unless($effective !== null
                && (int) $effective->reversal_journal_id === $reversalId
                && (int) $effective->folio_adjustment_id === $adjustmentId
                && ($effective->journal_id === null ? null : (int) $effective->journal_id) === $replacementJournalId,
                409, 'Completed correction failed effective clearance validation.');
            return $this->correctionResult($proposal, $effective, false);
        }, 3);
    }

    /** Stable posting receipt; projections reflect the approved snapshot, not later settlements. */
    private function correctionResult(object $proposal, object $effective, bool $replayed): array
    {
        $snapshot = json_decode($proposal->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $gross = (int) $effective->gross_minor;
        $funds = (int) $snapshot['preview']['verifiedFundsMinor'];
        return [
            'proposalId' => (string) $proposal->id,
            'reversalJournalId' => (string) $effective->reversal_journal_id,
            'replacementJournalId' => $effective->journal_id === null ? null : (string) $effective->journal_id,
            'folioAdjustmentId' => (string) $effective->folio_adjustment_id,
            'replacementFeeMinor' => $gross, 'verifiedDepositMinor' => $funds,
            'projectedCollectionMinor' => max(0, $gross - $funds),
            'projectedExcessDepositMinor' => max(0, $funds - $gross),
            'projectionBasis' => 'approved_correction_snapshot',
            'effectiveClearanceValid' => true, 'replayed' => $replayed, 'refundIssued' => false,
        ];
    }

    private function snapshot(Hotel $hotel, int $bookingId, int $actorId, int $replacementMinor): array
    {
        $preview = app(NoShowFeeReversalPreview::class)->preview($hotel, $bookingId, $actorId, $replacementMinor);
        abort_unless($preview['eligibleForReview'] && $preview['blockers'] === [], 409,
            'Fee correction requires eligible, reconciled original accounting.');
        $hotelId = (int) $hotel->id;
        $folioId = DB::table('reservation_folios')->where('hotel_id', $hotelId)
            ->where('reservation_record_id', $bookingId)->value('id');
        $snapshot = ['preview' => $preview];
        foreach (['folio_entries', 'folio_adjustments', 'folio_payment_records', 'folio_settlement_verifications',
            'folio_settlement_allocations', 'folio_refund_requests'] as $table) {
            $snapshot[$table] = DB::table($table)->where('hotel_id', $hotelId)->where('folio_id', $folioId)
                ->orderBy('id')->get()->all();
        }
        $snapshot['review'] = DB::table('no_show_financial_reviews')->where('hotel_id', $hotelId)
            ->where('id', $preview['reviewId'])->first();
        $snapshot['clearance'] = DB::table('no_show_financial_clearances')->where('hotel_id', $hotelId)
            ->where('id', $preview['clearanceId'])->first();
        $snapshot['journal'] = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
            ->where('id', $preview['originalJournalId'])->first();
        $snapshot['journalLines'] = DB::table('financial_posting_lines')->where('journal_id', $preview['originalJournalId'])
            ->orderBy('line_number')->get()->all();
        return $snapshot;
    }

    private function enabled(Hotel $hotel): void
    {
        abort_unless(config('financial_accounting.enable_no_show_fee_correction_review', false)
            && \App\Domain\Reservations\NoShowWorkflow::enabled((int) $hotel->id),
            409, 'Fee correction review is disabled.');
    }

    private function document(string $reason): string
    {
        $reason = trim($reason);
        abort_unless(mb_strlen($reason) >= 15 && mb_strlen($reason) <= 1000, 422, 'Document the correction and tax basis.');
        return $reason;
    }
}

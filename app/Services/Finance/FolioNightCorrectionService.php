<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Two-person, append-only accounting corrections to a previously approved
 * folio-night allocation. Does not edit its original invoice/source journal.
 */
final class FolioNightCorrectionService
{
    public function prepare(int $hotelId, int $assessmentId, int $actorId,
        string $benefitKind, int $eligibleGrossMinor, ?string $evidenceReference,
        string $reason): array
    {
        if (mb_strlen(trim($reason)) < 15 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A substantive correction reason is required.']);
        }
        return DB::transaction(function () use (
            $hotelId, $assessmentId, $actorId, $benefitKind, $eligibleGrossMinor, $evidenceReference, $reason
        ): array {
            $this->lockClock($hotelId);
            $assessment = $this->assessment($hotelId, $assessmentId);
            if (DB::table('financial_folio_night_corrections')
                ->where('hotel_id', $hotelId)->where('assessment_id', $assessmentId)
                ->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['assessment' => 'Resolve the pending correction first.']);
            }
            $policy = $this->policy($assessment);
            $allocation = $this->allocation($assessment, $policy, $benefitKind,
                $eligibleGrossMinor, $evidenceReference);
            $previous = $this->current($hotelId, $assessment);
            if ($allocation['folio_credit_minor'] === $previous['folio_credit_minor']
                && $allocation['vat_minor'] === $previous['vat_minor']
                && $allocation['room_revenue_minor'] === $previous['room_revenue_minor']) {
                throw ValidationException::withMessages(['allocation' => 'The correction makes no accounting change.']);
            }
            $id = DB::table('financial_folio_night_corrections')->insertGetId([
                'hotel_id' => $hotelId, 'assessment_id' => $assessmentId,
                'benefit_kind' => $benefitKind, 'eligible_gross_minor' => $eligibleGrossMinor,
                'evidence_reference' => $evidenceReference,
                'folio_credit_minor' => $allocation['folio_credit_minor'],
                'vat_minor' => $allocation['vat_minor'],
                'room_revenue_minor' => $allocation['room_revenue_minor'],
                'payable_minor' => $allocation['payable_minor'],
                'reason' => trim($reason), 'status' => 'pending',
                'prepared_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => (string) $id, 'status' => 'pending'];
        }, 3);
    }

    public function approve(int $hotelId, int $correctionId, int $reviewerId): array
    {
        return DB::transaction(function () use ($hotelId, $correctionId, $reviewerId): array {
            $clock = $this->lockClock($hotelId);
            $correction = DB::table('financial_folio_night_corrections')
                ->where('hotel_id', $hotelId)->where('id', $correctionId)
                ->lockForUpdate()->first();
            if ($correction === null || $correction->status !== 'pending') {
                throw ValidationException::withMessages(['correction' => 'Only pending corrections can be approved.']);
            }
            if ((int) $correction->prepared_by === $reviewerId) {
                throw ValidationException::withMessages(['reviewer' => 'A different administrator must approve this correction.']);
            }
            $assessment = $this->assessment($hotelId, (int) $correction->assessment_id);
            if ($assessment->source_journal_id === null) {
                throw ValidationException::withMessages(['assessment' => 'Original journal has not been posted.']);
            }
            $policy = $this->policy($assessment);
            $allocation = $this->allocation($assessment, $policy, $correction->benefit_kind,
                (int) $correction->eligible_gross_minor, $correction->evidence_reference);
            foreach (['folio_credit_minor', 'vat_minor', 'room_revenue_minor', 'payable_minor'] as $key) {
                if ((int) $correction->{$key} !== $allocation[$key]) {
                    throw ValidationException::withMessages(['correction' => 'Correction calculation changed.']);
                }
            }
            $previous = $this->current($hotelId, $assessment);
            $deltaCredit = $allocation['folio_credit_minor'] - $previous['folio_credit_minor'];
            $deltaVat = $allocation['vat_minor'] - $previous['vat_minor'];
            $deltaRevenue = $allocation['room_revenue_minor'] - $previous['room_revenue_minor'];
            if ($deltaCredit + $deltaVat + $deltaRevenue !== 0
                || ($deltaCredit === 0 && $deltaVat === 0 && $deltaRevenue === 0)) {
                throw ValidationException::withMessages(['correction' => 'Correction is stale or unbalanced.']);
            }
            // Lock the existing folio before checking settlements and creating
            // compensating credit/debit adjustments.
            DB::table('reservation_folios')->where('hotel_id', $hotelId)
                ->where('id', $assessment->folio_id)->lockForUpdate()->firstOrFail();
            if (DB::table('folio_payment_records')->where('hotel_id', $hotelId)
                ->where('folio_id', $assessment->folio_id)->exists()) {
                throw ValidationException::withMessages(['folio' =>
                    'Payment evidence exists; use the refund and reconciliation workflow first.']);
            }
            if (DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                ->where('business_date', CarbonImmutable::parse($clock->business_date)->toDateString())->exists()) {
                throw ValidationException::withMessages(['business_date' => 'Cannot correct on a closed business date.']);
            }

            // If the night was already earned, correct ROOM_REV; otherwise
            // adjust the remaining DEFERRED_ROOM_REV balance.
            $earned = DB::table('financial_room_revenue_recognitions')
                ->where('hotel_id', $hotelId)
                ->where('source_journal_id', $assessment->source_journal_id)->first();
            if ($earned !== null && DB::table('financial_posting_journals')
                ->where('hotel_id', $hotelId)
                ->where('reverses_journal_id', $earned->recognition_journal_id)->exists()) {
                throw ValidationException::withMessages(['recognition' => 'Reversed recognition requires manual reconciliation.']);
            }
            $lines = [];
            foreach ([
                'AR' => $deltaCredit,
                'VAT_PAYABLE' => $deltaVat,
                ($earned === null ? 'DEFERRED_ROOM_REV' : 'ROOM_REV') => $deltaRevenue,
            ] as $account => $creditNet) {
                if ($creditNet !== 0) {
                    $lines[] = [
                        'account_code' => $account,
                        'debit_minor' => $creditNet < 0 ? -$creditNet : 0,
                        'credit_minor' => $creditNet > 0 ? $creditNet : 0,
                    ];
                }
            }
            $journalId = (new FinancialJournalPostingService())->post(
                $hotelId, 'folio-tax-correction-'.$correctionId,
                'folio_tax_correction', (string) $correctionId,
                'Approved tax and benefit correction #'.$correctionId,
                $lines, $reviewerId
            );
            $adjustmentId = null;
            if ($deltaCredit !== 0) {
                $adjustmentId = DB::table('folio_adjustments')->insertGetId([
                    'hotel_id' => $hotelId, 'folio_id' => $assessment->folio_id,
                    'request_key' => 'tax-correction-'.$correctionId,
                    'payload_sha256' => hash('sha256', json_encode([
                        $correctionId, $deltaCredit, $journalId
                    ], JSON_THROW_ON_ERROR)),
                    'kind' => $deltaCredit > 0 ? 'credit' : 'debit',
                    'amount_minor' => abs($deltaCredit),
                    'reason' => 'Manager-reviewed tax/benefit correction #'.$correctionId,
                    'actor_id' => $reviewerId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('financial_folio_night_corrections')
                ->where('id', $correctionId)->where('hotel_id', $hotelId)->update([
                    'status' => 'approved', 'reviewed_by' => $reviewerId,
                    'reviewed_at' => now(), 'journal_id' => $journalId,
                    'folio_adjustment_id' => $adjustmentId, 'updated_at' => now(),
                ]);
            return [
                'id' => (string) $correctionId, 'status' => 'approved',
                'journalId' => (string) $journalId,
                'folioAdjustmentId' => $adjustmentId === null ? null : (string) $adjustmentId,
                'deltaFolioCreditMinor' => $deltaCredit,
                'deltaVatMinor' => $deltaVat,
                'deltaRevenueMinor' => $deltaRevenue,
            ];
        }, 3);
    }

    private function lockClock(int $hotelId): object
    {
        $clock = DB::table('financial_business_clocks')
            ->where('hotel_id', $hotelId)->lockForUpdate()->first();
        if ($clock === null || $clock->status !== 'open') {
            throw ValidationException::withMessages(['business_date' => 'Open business clock required.']);
        }
        return $clock;
    }

    private function assessment(int $hotelId, int $assessmentId): object
    {
        $row = DB::table('financial_folio_night_assessments')
            ->where('hotel_id', $hotelId)->where('id', $assessmentId)
            ->where('status', 'approved')->lockForUpdate()->first();
        if ($row === null) {
            throw ValidationException::withMessages(['assessment' => 'Approved original assessment required.']);
        }
        return $row;
    }

    private function policy(object $assessment): object
    {
        $row = DB::table('financial_tax_policies')
            ->where('hotel_id', $assessment->hotel_id)
            ->where('id', $assessment->tax_policy_id)
            ->where('status', 'approved')->first();
        if ($row === null) {
            throw ValidationException::withMessages(['tax_policy' => 'Original policy unavailable.']);
        }
        return $row;
    }

    private function allocation(object $assessment, object $policy,
        string $benefitKind, int $eligibleGrossMinor, ?string $reference): array
    {
        if (($benefitKind === 'none' && $reference !== null)
            || ($benefitKind !== 'none' && ($reference === null
                || mb_strlen(trim($reference)) < 6 || mb_strlen($reference) > 160))) {
            throw ValidationException::withMessages(['evidenceReference' => 'Verified benefit evidence reference required.']);
        }
        try {
            return (new FolioNightTaxAllocation())->calculate(
                (int) $assessment->gross_minor, $policy->classification, $policy->price_mode,
                (int) $policy->rate_bps, $benefitKind, $eligibleGrossMinor
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['allocation' => $exception->getMessage()]);
        }
    }

    public function current(int $hotelId, object $assessment): array
    {
        $latest = DB::table('financial_folio_night_corrections')
            ->where('hotel_id', $hotelId)->where('assessment_id', $assessment->id)
            ->where('status', 'approved')->orderByDesc('id')->first();
        $row = $latest ?? $assessment;
        return [
            'folio_credit_minor' => (int) $row->folio_credit_minor,
            'vat_minor' => (int) $row->vat_minor,
            'room_revenue_minor' => (int) $row->room_revenue_minor,
            'payable_minor' => (int) $row->payable_minor,
        ];
    }
}

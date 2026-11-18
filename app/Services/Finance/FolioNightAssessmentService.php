<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Explicit two-person approval of EXISTING reservation_folios/folio_entries.
 * Every write uses the hotel's financial clock lock, then the folio lock.
 * No checkout charges, payment records or tax invoices are created.
 */
final class FolioNightAssessmentService
{
    public function prepare(int $hotelId, int $entryId, int $policyId, int $actorId,
        string $benefitKind = 'none', int $eligibleGrossMinor = 0,
        ?string $evidenceReference = null): array
    {
        return DB::transaction(function () use (
            $hotelId, $entryId, $policyId, $actorId, $benefitKind, $eligibleGrossMinor, $evidenceReference
        ): array {
            $this->lockClock($hotelId);
            $context = $this->context($hotelId, $entryId, true);
            $policy = $this->policy($hotelId, $policyId, $context->stay_date);
            $allocation = $this->allocation($context, $policy, $benefitKind,
                $eligibleGrossMinor, $evidenceReference);
            $fingerprint = hash('sha256', json_encode([
                $hotelId, $entryId, $policyId, (int) $context->amount_minor,
                $benefitKind, $eligibleGrossMinor, $evidenceReference,
                $allocation,
            ], JSON_THROW_ON_ERROR));

            $previous = DB::table('financial_folio_night_assessments')
                ->where('hotel_id', $hotelId)->where('folio_entry_id', $entryId)
                ->lockForUpdate()->first();
            if ($previous !== null) {
                if (!hash_equals($previous->payload_sha256, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'folio_entry_id' => 'This folio night already has a different assessment; use the correction workflow.',
                    ]);
                }
                return $this->present($previous);
            }
            $id = DB::table('financial_folio_night_assessments')->insertGetId([
                'hotel_id' => $hotelId,
                'reservation_record_id' => $context->reservation_record_id,
                'folio_id' => $context->folio_id, 'folio_entry_id' => $entryId,
                'tax_policy_id' => $policyId, 'stay_date' => $context->stay_date,
                'gross_minor' => $context->amount_minor,
                'eligible_gross_minor' => $eligibleGrossMinor,
                'benefit_kind' => $benefitKind, 'evidence_reference' => $evidenceReference,
                'payload_sha256' => $fingerprint,
                'folio_credit_minor' => $allocation['folio_credit_minor'],
                'vat_minor' => $allocation['vat_minor'],
                'room_revenue_minor' => $allocation['room_revenue_minor'],
                'payable_minor' => $allocation['payable_minor'],
                'status' => 'pending', 'prepared_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->present(DB::table('financial_folio_night_assessments')->where('id', $id)->first());
        }, 3);
    }

    public function approve(int $hotelId, int $assessmentId, int $reviewerId): array
    {
        return DB::transaction(function () use ($hotelId, $assessmentId, $reviewerId): array {
            $clock = $this->lockClock($hotelId);
            $assessment = DB::table('financial_folio_night_assessments')
                ->where('hotel_id', $hotelId)->where('id', $assessmentId)
                ->lockForUpdate()->first();
            if ($assessment === null || $assessment->status !== 'pending') {
                throw ValidationException::withMessages(['assessment' => 'Only pending assessments can be approved.']);
            }
            if ((int) $assessment->prepared_by === $reviewerId) {
                throw ValidationException::withMessages(['reviewer' => 'A second administrator must review the assessment.']);
            }
            if ($assessment->stay_date < CarbonImmutable::parse($clock->business_date)->toDateString()) {
                throw ValidationException::withMessages([
                    'stay_date' => 'Historical folio nights require a separate reviewed backfill process.',
                ]);
            }
            $context = $this->context($hotelId, (int) $assessment->folio_entry_id, true);
            if ((int) $context->folio_id !== (int) $assessment->folio_id
                || (int) $context->reservation_record_id !== (int) $assessment->reservation_record_id
                || (int) $context->amount_minor !== (int) $assessment->gross_minor
                || $context->stay_date !== $assessment->stay_date) {
                throw ValidationException::withMessages(['assessment' => 'Folio entry changed; approval blocked.']);
            }
            $policy = $this->policy($hotelId, (int) $assessment->tax_policy_id, $assessment->stay_date);
            $allocation = $this->allocation($context, $policy, $assessment->benefit_kind,
                (int) $assessment->eligible_gross_minor, $assessment->evidence_reference);
            foreach ([
                'folio_credit_minor', 'vat_minor', 'room_revenue_minor', 'payable_minor'
            ] as $key) {
                if ((int) $assessment->{$key} !== $allocation[$key]) {
                    throw ValidationException::withMessages(['assessment' => 'Approved tax calculation differs from prepared snapshot.']);
                }
            }

            // Do not apply a discount over existing adjustments or payment evidence:
            // a settled/partially paid folio needs a separate refund workflow.
            if ((int) $assessment->folio_credit_minor > 0 &&
                (DB::table('folio_adjustments')->where('hotel_id', $hotelId)
                    ->where('folio_id', $assessment->folio_id)->exists()
                || DB::table('folio_payment_records')->where('hotel_id', $hotelId)
                    ->where('folio_id', $assessment->folio_id)->exists())) {
                throw ValidationException::withMessages(['benefit_kind' =>
                    'Existing folio adjustments or payment evidence require a reviewed refund/reconciliation workflow.']);
            }

            $sourceReference = 'folio-entry-'.(int) $context->id;
            if (DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                ->where('source_reference', $sourceReference)->exists()) {
                throw ValidationException::withMessages(['folio_entry_id' =>
                    'An accounting source already exists for this folio entry.']);
            }

            $posting = new FinancialJournalPostingService();
            $gross = (int) $assessment->gross_minor;
            $sourceId = $posting->post($hotelId, 'folio-night-source-'.$context->id,
                'folio_deferred', $sourceReference, 'Deferred source for existing room-night folio entry',
                [
                    ['account_code' => 'AR', 'debit_minor' => $gross, 'credit_minor' => 0],
                    ['account_code' => 'DEFERRED_ROOM_REV', 'debit_minor' => 0, 'credit_minor' => $gross],
                ], $reviewerId);

            $benefitJournalId = null;
            $credit = (int) $assessment->folio_credit_minor;
            if ($credit > 0) {
                DB::table('folio_adjustments')->insert([
                    'hotel_id' => $hotelId, 'folio_id' => $assessment->folio_id,
                    'request_key' => 'tax-benefit-'.$assessmentId,
                    'payload_sha256' => $assessment->payload_sha256,
                    'kind' => 'credit', 'amount_minor' => $credit,
                    'reason' => 'Approved statutory room-night benefit (VAT relief and discount) for folio entry #'.$context->id,
                    'actor_id' => $reviewerId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $benefitJournalId = $posting->post($hotelId, 'folio-night-benefit-'.$assessmentId,
                    'folio_benefit', (string) $assessmentId,
                    'Approved guest benefit on existing room night',
                    [
                        ['account_code' => 'DEFERRED_ROOM_REV', 'debit_minor' => $credit, 'credit_minor' => 0],
                        ['account_code' => 'AR', 'debit_minor' => 0, 'credit_minor' => $credit],
                    ], $reviewerId);
            }

            $vatJournalId = null;
            $vat = (int) $assessment->vat_minor;
            if ($vat > 0) {
                $vatJournalId = $posting->post($hotelId, 'folio-night-vat-'.$assessmentId,
                    'vat_liability', (string) $assessmentId,
                    'Manager-reviewed VAT allocation for existing room night',
                    [
                        ['account_code' => 'DEFERRED_ROOM_REV', 'debit_minor' => $vat, 'credit_minor' => 0],
                        ['account_code' => 'VAT_PAYABLE', 'debit_minor' => 0, 'credit_minor' => $vat],
                    ], $reviewerId);
            }
            DB::table('financial_folio_night_assessments')->where('id', $assessmentId)->update([
                'status' => 'approved', 'reviewed_by' => $reviewerId,
                'reviewed_at' => now(), 'source_journal_id' => $sourceId,
                'benefit_journal_id' => $benefitJournalId, 'vat_journal_id' => $vatJournalId,
                'updated_at' => now(),
            ]);
            return $this->present(DB::table('financial_folio_night_assessments')->where('id', $assessmentId)->first());
        }, 3);
    }

    public function recognize(int $hotelId, int $assessmentId, int $actorId): int
    {
        if (Schema::hasTable('folio_settlement_signoffs')
            && DB::table('financial_folio_night_assessments as a')
                ->join('folio_settlement_signoffs as s', 's.folio_id', '=', 'a.folio_id')
                ->where('a.hotel_id', $hotelId)->where('s.hotel_id', $hotelId)
                ->where('a.id', $assessmentId)->exists()) {
            throw ValidationException::withMessages([
                'folio' => 'Recognition must finish before final guest settlement sign-off.',
            ]);
        }

        if (!config('financial_accounting.enable_live_folio_tax', false)) {
            throw ValidationException::withMessages([
                'activation' => 'Live folio tax accounting is not activated for this environment.',
            ]);
        }
        $assessment = DB::table('financial_folio_night_assessments')
            ->where('hotel_id', $hotelId)->where('id', $assessmentId)->first();
        if ($assessment === null || $assessment->status !== 'approved' || $assessment->source_journal_id === null) {
            throw ValidationException::withMessages(['assessment' => 'An approved folio-night assessment is required.']);
        }
        $existing = DB::table('financial_room_revenue_recognitions')
            ->where('hotel_id', $hotelId)
            ->where('source_journal_id', $assessment->source_journal_id)
            ->where('stay_date', $assessment->stay_date)->first();
        if ($existing !== null) {
            if (DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                ->where('reverses_journal_id', $existing->recognition_journal_id)->exists()) {
                throw ValidationException::withMessages([
                    'recognition' => 'Reversed revenue recognition requires manual reconciliation.',
                ]);
            }
            return (int) $existing->recognition_journal_id;
        }
        if (DB::table('financial_folio_night_corrections')->where('hotel_id', $hotelId)
            ->where('assessment_id', $assessmentId)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['correction' => 'Approve or resolve the pending correction first.']);
        }
        $current = (new FolioNightCorrectionService())->current($hotelId, $assessment);
        return (new NightlyRoomRevenueRecognitionService())->recognize(
            $hotelId, (int) $assessment->source_journal_id,
            $assessment->stay_date, $current['room_revenue_minor'], $actorId
        );
    }

    public function listing(int $hotelId, int $folioId): array
    {
        return DB::table('financial_folio_night_assessments')
            ->where('hotel_id', $hotelId)->where('folio_id', $folioId)
            ->orderBy('stay_date')->get()->map(fn ($row) => $this->present($row))->all();
    }

    private function lockClock(int $hotelId): object
    {
        if (!config('financial_accounting.enable_live_folio_tax', false)) {
            throw ValidationException::withMessages([
                'activation' => 'Live folio tax accounting requires approved deployment and finance activation.',
            ]);
        }
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)
            ->lockForUpdate()->first();
        if ($clock === null || $clock->status !== 'open'
            || DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                ->where('business_date', CarbonImmutable::parse($clock->business_date)->toDateString())->exists()) {
            throw ValidationException::withMessages(['business_date' => 'Open financial clock is required.']);
        }
        return $clock;
    }

    private function context(int $hotelId, int $entryId, bool $lock): object
    {
        $query = DB::table('folio_entries as e')
            ->join('reservation_folios as f', 'f.id', '=', 'e.folio_id')
            ->join('reservation_records as r', 'r.id', '=', 'f.reservation_record_id')
            ->where('e.hotel_id', $hotelId)->where('f.hotel_id', $hotelId)
            ->where('r.hotel_id', $hotelId)->where('e.id', $entryId)
            ->where('e.entry_type', 'room_night')
            ->select('e.id', 'e.folio_id', 'e.amount_minor', 'e.stay_date',
                'f.currency', 'f.reservation_record_id', 'r.lifecycle_state',
                'r.check_in', 'r.check_out');
        $entry = $lock ? $query->lockForUpdate()->first() : $query->first();
        if ($entry !== null && Schema::hasTable('folio_settlement_signoffs')
            && DB::table('folio_settlement_signoffs')
                ->where('hotel_id', $hotelId)->where('folio_id', $entry->folio_id)->exists()) {
            throw ValidationException::withMessages([
                'folio' => 'A signed guest folio cannot accept new tax assessment or adjustments.',
            ]);
        }
        if ($entry === null || $entry->currency !== 'PHP'
            || in_array($entry->lifecycle_state, ['cancelled'], true)
            || $entry->stay_date < $entry->check_in || $entry->stay_date >= $entry->check_out) {
            throw ValidationException::withMessages(['folio_entry_id' => 'Active PHP room-night folio entry required.']);
        }
        return $entry;
    }

    private function policy(int $hotelId, int $policyId, string $date): object
    {
        $policy = DB::table('financial_tax_policies')->where('hotel_id', $hotelId)
            ->where('id', $policyId)->where('status', 'approved')
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')
                ->orWhere('effective_until', '>=', $date))->first();
        if ($policy === null) {
            throw ValidationException::withMessages(['tax_policy_id' => 'Approved policy does not cover this stay date.']);
        }
        return $policy;
    }

    private function allocation(object $entry, object $policy, string $benefitKind,
        int $eligibleGrossMinor, ?string $reference): array
    {
        if ($benefitKind !== 'none' && ($reference === null
            || mb_strlen(trim($reference)) < 6 || mb_strlen($reference) > 160)) {
            throw ValidationException::withMessages(['evidence_reference' =>
                'Verified statutory benefit evidence reference is required.']);
        }
        if ($benefitKind === 'none' && $reference !== null) {
            throw ValidationException::withMessages(['evidence_reference' =>
                'Evidence reference must be omitted when no benefit is applied.']);
        }
        try {
            return (new FolioNightTaxAllocation())->calculate(
                (int) $entry->amount_minor, $policy->classification,
                $policy->price_mode, (int) $policy->rate_bps, $benefitKind, $eligibleGrossMinor
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['allocation' => $exception->getMessage()]);
        }
    }

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id, 'hotelId' => (string) $row->hotel_id,
            'folioId' => (string) $row->folio_id,
            'folioEntryId' => (string) $row->folio_entry_id,
            'taxPolicyId' => (string) $row->tax_policy_id,
            'stayDate' => $row->stay_date, 'status' => $row->status,
            'grossMinor' => (int) $row->gross_minor,
            'benefitKind' => $row->benefit_kind,
            'eligibleGrossMinor' => (int) $row->eligible_gross_minor,
            'folioCreditMinor' => (int) $row->folio_credit_minor,
            'vatMinor' => (int) $row->vat_minor,
            'roomRevenueMinor' => (int) $row->room_revenue_minor,
            'payableMinor' => (int) $row->payable_minor,
            'preparedBy' => (string) $row->prepared_by,
            'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
            'sourceJournalId' => $row->source_journal_id === null ? null : (string) $row->source_journal_id,
            'benefitJournalId' => $row->benefit_journal_id === null ? null : (string) $row->benefit_journal_id,
            'vatJournalId' => $row->vat_journal_id === null ? null : (string) $row->vat_journal_id,
        ];
    }
}

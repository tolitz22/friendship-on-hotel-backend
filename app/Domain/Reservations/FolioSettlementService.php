<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 61: settlement evidence and allocation without replacing the folio.
 *
 * Verified means independently reviewed documentary evidence or a reconciled
 * cashier shift; it does not mean PSP/bank API confirmation. No refund is
 * disbursed here. Booking charges/payments remain in their original tables.
 */
final class FolioSettlementService
{
    private function lockedFolio(Hotel $hotel, int $bookingId): object
    {
        if (!config('financial_accounting.enable_verified_settlement', false)) {
            throw ValidationException::withMessages([
                'activation' => 'Verified settlement writes require the staged finance activation setting.',
            ]);
        }
        app(ReadinessWriteGuard::class)->lockHotel($hotel);
        NoShowWorkflow::lockClockForNoShow($hotel, $bookingId);
        // Simplified checkout deliberately happens before shift review, but
        // post-departure financial mutations require a separate approved
        // correction flow, not an ordinary pre-checkout endpoint.
        if (config('financial_accounting.simplified_cashier_mode', false)
            && DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->where('lifecycle_state', 'checked_out')->exists()) {
            throw new HttpException(409,
                'The guest has checked out; use an owner-approved post-checkout finance correction workflow.');
        }
        return app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
    }

    private function payment(Hotel $hotel, object $folio, int $paymentId): object
    {
        $row = DB::table('folio_payment_records')
            ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
            ->where('id', $paymentId)->lockForUpdate()->first();
        abort_unless($row !== null, 404);
        return $row;
    }

    public function prepareVerification(Hotel $hotel, int $bookingId, int $paymentId,
        int $actorId, string $kind, string $reference): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $paymentId, $actorId, $kind, $reference): array {
            $folio = $this->lockedFolio($hotel, $bookingId);
            $payment = $this->payment($hotel, $folio, $paymentId);
            $this->assertUnsigned($folio);
            if ($payment->status !== 'staff_confirmed') {
                throw ValidationException::withMessages(['payment' => 'Staff-confirmed payment evidence required.']);
            }
            if (!in_array($kind, ['bank_statement', 'cash_shift'], true)
                || trim($reference) === '' || mb_strlen(trim($reference)) < 6
                || mb_strlen($reference) > 160
                || ($payment->method === 'cash' && $kind !== 'cash_shift')
                || ($payment->method === 'bank_transfer' && $kind !== 'bank_statement')) {
                throw ValidationException::withMessages(['evidence' => 'Evidence type and reference must match the payment method.']);
            }
            if ($payment->method === 'bank_transfer'
                && DB::table('folio_settlement_verifications')
                    ->where('hotel_id', $hotel->id)
                    ->where('payment_record_id', '!=', $paymentId)
                    ->where('evidence_kind', 'bank_statement')
                    ->where('evidence_reference', trim($reference))
                    ->whereIn('status', ['pending', 'verified'])->exists()) {
                throw ValidationException::withMessages([
                    'evidence' => 'Bank evidence reference is already associated with another payment.',
                ]);
            }
            $existing = DB::table('folio_settlement_verifications')
                ->where('hotel_id', $hotel->id)->where('payment_record_id', $paymentId)->first();
            if ($existing !== null) {
                if ($existing->evidence_kind !== $kind || $existing->evidence_reference !== trim($reference)) {
                    throw ValidationException::withMessages(['payment' => 'This payment already has a different verification.']);
                }
                return $this->verification($existing);
            }
            $id = DB::table('folio_settlement_verifications')->insertGetId([
                'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                'payment_record_id' => $paymentId, 'evidence_kind' => $kind,
                'evidence_reference' => trim($reference), 'status' => 'pending',
                'prepared_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->verification(DB::table('folio_settlement_verifications')->find($id));
        }, 3);
    }

    public function reviewVerification(Hotel $hotel, int $bookingId, int $verificationId,
        int $reviewerId, string $decision, string $note): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $verificationId, $reviewerId, $decision, $note): array {
            $folio = $this->lockedFolio($hotel, $bookingId);
            $this->assertUnsigned($folio);
            $verification = DB::table('folio_settlement_verifications')
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->where('id', $verificationId)->lockForUpdate()->first();
            abort_unless($verification !== null, 404);
            if ($verification->status !== 'pending') {
                throw ValidationException::withMessages(['verification' => 'Only pending evidence can be reviewed.']);
            }
            if ($decision === 'verify' && (int) $verification->prepared_by === $reviewerId) {
                throw ValidationException::withMessages(['reviewer' => 'A different administrator must verify payment evidence.']);
            }
            if (!in_array($decision, ['verify', 'reject'], true) || mb_strlen(trim($note)) < 10) {
                throw ValidationException::withMessages(['decision' => 'A decision and substantive review note are required.']);
            }
            $payment = $this->payment($hotel, $folio, (int) $verification->payment_record_id);
            if ($payment->status !== 'staff_confirmed') {
                throw ValidationException::withMessages(['payment' => 'Payment is no longer staff confirmed.']);
            }
            if ($decision === 'verify' && (int) $payment->recorded_by === $reviewerId) {
                throw ValidationException::withMessages([
                    'reviewer' => 'The payment recorder cannot independently verify their own evidence.',
                ]);
            }
            if ($decision === 'verify' && $payment->method === 'cash') {
                $receipt = DB::table('cashier_shift_receipts as sr')
                    ->join('cashier_shifts as cs', 'cs.id', '=', 'sr.shift_id')
                    ->where('sr.hotel_id', $hotel->id)->where('cs.hotel_id', $hotel->id)
                    ->where('sr.payment_id', $payment->id)->where('cs.status', 'reconciled')
                    ->where('sr.amount_minor', $payment->amount_minor)->first(['cs.id']);
                if ($receipt === null) {
                    throw ValidationException::withMessages(['payment' => 'Cash shift must be reconciled before verification.']);
                }
                if ($verification->evidence_reference !== 'shift-'.$receipt->id) {
                    throw ValidationException::withMessages(['evidence' => 'Cash evidence must match the reconciled shift reference.']);
                }
            }
            DB::table('folio_settlement_verifications')
                ->where('id', $verificationId)->where('hotel_id', $hotel->id)->update([
                    'status' => $decision === 'verify' ? 'verified' : 'rejected',
                    'reviewed_by' => $reviewerId, 'review_note' => trim($note),
                    'reviewed_at' => now(), 'updated_at' => now(),
                ]);
            return $this->verification(DB::table('folio_settlement_verifications')->find($verificationId));
        }, 3);
    }

    public function allocate(Hotel $hotel, int $bookingId, int $paymentId, int $actorId,
        string $key, string $payerKind, string $payerReference, int $amountMinor, ?int $entryId): array
    {
        return DB::transaction(function () use (
            $hotel, $bookingId, $paymentId, $actorId, $key, $payerKind, $payerReference, $amountMinor, $entryId
        ): array {
            $folio = $this->lockedFolio($hotel, $bookingId);
            $this->assertUnsigned($folio);
            if ($amountMinor < 1 || $amountMinor > 100000000000
                || !in_array($payerKind, ['guest', 'company', 'group'], true)
                || mb_strlen(trim($payerReference)) < 3 || mb_strlen($payerReference) > 120
                || mb_strlen($key) < 8 || mb_strlen($key) > 100) {
                throw ValidationException::withMessages(['allocation' => 'Invalid allocation payer, amount or request key.']);
            }
            $existing = DB::table('folio_settlement_allocations')->where('hotel_id', $hotel->id)
                ->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ((int) $existing->folio_id !== (int) $folio->id
                    || (int) $existing->payment_record_id !== $paymentId
                    || $existing->payer_kind !== $payerKind
                    || $existing->payer_reference !== trim($payerReference)
                    || (int) $existing->amount_minor !== $amountMinor
                    || (int) ($existing->folio_entry_id ?? 0) !== ($entryId ?? 0)) {
                    throw ValidationException::withMessages(['requestKey' => 'Allocation key already used for different details.']);
                }
                return ['id' => (string) $existing->id, 'replayed' => true];
            }
            $payment = $this->payment($hotel, $folio, $paymentId);
            $verified = DB::table('folio_settlement_verifications')
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->where('payment_record_id', $paymentId)->where('status', 'verified')->exists();
            if ($payment->status !== 'staff_confirmed' || !$verified) {
                throw ValidationException::withMessages(['payment' => 'Independently verified payment evidence required for allocation.']);
            }
            $allocated = (int) DB::table('folio_settlement_allocations')
                ->where('hotel_id', $hotel->id)->where('payment_record_id', $paymentId)->sum('amount_minor');
            $reservedRefunds = (int) DB::table('folio_refund_requests')
                ->where('hotel_id', $hotel->id)->where('payment_record_id', $paymentId)
                ->whereIn('status', ['requested', 'approved'])->sum('amount_minor');
            $net = app(FolioAccountingService::class)->summaryFor($folio)['netRecordedChargesMinor'];
            if (DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->where('lifecycle_state', 'no_show')->exists()) {
                if ($entryId !== null) {
                    throw ValidationException::withMessages(['folioEntryId' => 'No-show fees require a folio-level allocation, not an unused room night.']);
                }
                $clearance = app(NoShowRefundEligibility::class)->review($hotel, $bookingId);
                if (!NoShowWorkflow::enabled((int) $hotel->id) || !$clearance['financialClearanceValid']) {
                    throw ValidationException::withMessages(['allocation' => 'Complete no-show financial clearance before allocating payments.']);
                }
            }
            $folioAllocated = (int) DB::table('folio_settlement_allocations')
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->sum('amount_minor');
            if ($net < 0 || $folioAllocated > $net || $amountMinor > $net - $folioAllocated) {
                throw ValidationException::withMessages([
                    'amountMinor' => 'Allocations cannot exceed the folio net charges after credits and discounts.',
                ]);
            }
            if ($allocated > (int) $payment->amount_minor
                || $reservedRefunds > (int) $payment->amount_minor - $allocated
                || $amountMinor > (int) $payment->amount_minor - $allocated - $reservedRefunds) {
                throw ValidationException::withMessages(['amountMinor' => 'Payment allocation exceeds verified payment amount.']);
            }
            if ($entryId !== null) {
                $entry = ActiveFolioEntryScope::apply(DB::table('folio_entries'))->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->where('id', $entryId)->first();
                abort_unless($entry !== null, 404);
                $entryAllocated = (int) DB::table('folio_settlement_allocations')
                    ->where('hotel_id', $hotel->id)->where('folio_entry_id', $entryId)->sum('amount_minor');
                if ($entryAllocated > (int) $entry->amount_minor
                    || $amountMinor > (int) $entry->amount_minor - $entryAllocated) {
                    throw ValidationException::withMessages(['folioEntryId' => 'Allocation exceeds the original room-night charge.']);
                }
            }
            $id = DB::table('folio_settlement_allocations')->insertGetId([
                'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                'payment_record_id' => $paymentId, 'folio_entry_id' => $entryId,
                'payer_kind' => $payerKind, 'payer_reference' => trim($payerReference),
                'amount_minor' => $amountMinor, 'idempotency_key' => $key,
                'allocated_by' => $actorId, 'created_at' => now(),
            ]);
            return ['id' => (string) $id, 'replayed' => false];
        }, 3);
    }

    public function signoff(Hotel $hotel, int $bookingId, int $actorId): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actorId): array {
            $folio = $this->lockedFolio($hotel, $bookingId);
            $existing = DB::table('folio_settlement_signoffs')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->first();
            if ($existing !== null) {
                return ['id' => (string) $existing->id, 'replayed' => true];
            }
            $statement = $this->statement($hotel, $bookingId);
            if ($statement['status'] !== 'checked_in'
                || now($hotel->timezone ?: 'Asia/Manila')->toDateString() < $statement['checkOut']) {
                throw ValidationException::withMessages([
                    'booking' => 'A registered in-house guest at or after scheduled departure is required for checkout sign-off.',
                ]);
            }
            if (!$statement['canSignOff']) {
                throw ValidationException::withMessages(['settlement' => implode(' ', $statement['blockers'])]);
            }
            // Cashier shift state, staff display names, and review handoff are
            // mutable operational metadata. Keep them in the API response,
            // but never embed them in the immutable financial sign-off snapshot.
            $snapshot = $statement;
            unset($snapshot['cashShiftContexts'], $snapshot['simplifiedCashierModeEnabled'],
                $snapshot['routineCashCheckoutEligible'], $snapshot['routineCashCheckoutReason'],
                $snapshot['routineCashCheckoutAudit']);
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
            $id = DB::table('folio_settlement_signoffs')->insertGetId([
                'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                'reservation_record_id' => $bookingId, 'approved_by' => $actorId,
                'net_charges_minor' => $statement['netRecordedChargesMinor'],
                'verified_allocated_minor' => $statement['verifiedAllocatedMinor'],
                'snapshot_sha256' => hash('sha256', $encoded),
                'statement_snapshot' => $encoded, 'signed_at' => now(),
            ]);
            return ['id' => (string) $id, 'replayed' => false];
        }, 3);
    }

    public function statement(Hotel $hotel, int $bookingId): array
    {
        $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId);
        $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('id', $bookingId)->first();
        abort_unless($booking !== null, 404);

        $summary = app(FolioAccountingService::class)->summaryFor($folio);
        $charges = ActiveFolioEntryScope::apply(DB::table('folio_entries'))->where('hotel_id', $hotel->id)
            ->where('folio_id', $folio->id)->orderBy('id')
            ->get(['id', 'entry_type', 'description', 'stay_date', 'amount_minor'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'type' => $r->entry_type,
                'description' => $r->description, 'stayDate' => $r->stay_date,
                'amountMinor' => (int) $r->amount_minor,
            ])->all();
        $taxAllocations = [];
        if (Schema::hasTable('financial_folio_night_assessments')) {
            $taxAllocations = DB::table('financial_folio_night_assessments')
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->orderBy('stay_date')->get(['folio_entry_id', 'stay_date', 'status',
                    'gross_minor', 'vat_minor', 'room_revenue_minor',
                    'folio_credit_minor', 'benefit_kind'])
                ->map(fn ($r) => [
                    'folioEntryId' => (string) $r->folio_entry_id,
                    'stayDate' => $r->stay_date, 'status' => $r->status,
                    'grossMinor' => (int) $r->gross_minor,
                    'vatMinor' => (int) $r->vat_minor,
                    'roomRevenueMinor' => (int) $r->room_revenue_minor,
                    'folioCreditMinor' => (int) $r->folio_credit_minor,
                    'benefitKind' => $r->benefit_kind,
                ])->all();
        }
        $adjustments = DB::table('folio_adjustments')
            ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
            ->orderBy('id')->get(['id', 'kind', 'reason', 'amount_minor'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'kind' => $r->kind, 'description' => $r->reason,
                'amountMinor' => (int) $r->amount_minor,
            ])->all();
        $payments = DB::table('folio_payment_records as p')
            ->leftJoin('folio_settlement_verifications as v', function ($join): void {
                $join->on('v.payment_record_id', '=', 'p.id')
                    ->on('v.hotel_id', '=', 'p.hotel_id');
            })
            ->where('p.hotel_id', $hotel->id)->where('p.folio_id', $folio->id)
            ->orderBy('p.id')->get(['p.id', 'p.method', 'p.reference',
                'p.amount_minor', 'p.status', 'v.status as verification_status',
                'v.id as verification_id', 'v.prepared_by as verification_prepared_by',
                'v.evidence_kind as verification_evidence_kind',
                'v.evidence_reference as verification_evidence_reference'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'method' => $r->method,
                'reference' => $r->reference, 'amountMinor' => (int) $r->amount_minor,
                'status' => $r->status, 'verificationStatus' => $r->verification_status,
                'verificationId' => $r->verification_id === null ? null : (string) $r->verification_id,
                'verificationPreparedBy' => $r->verification_prepared_by === null ? null : (string) $r->verification_prepared_by,
                'evidenceKind' => $r->verification_evidence_kind,
                'evidenceReference' => $r->verification_evidence_reference,
            ])->all();
        $allocations = DB::table('folio_settlement_allocations')
            ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
            ->orderBy('id')->get(['id', 'payment_record_id', 'folio_entry_id',
                'payer_kind', 'payer_reference', 'amount_minor'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'paymentId' => (string) $r->payment_record_id,
                'folioEntryId' => $r->folio_entry_id === null ? null : (string) $r->folio_entry_id,
                'payerKind' => $r->payer_kind, 'payerReference' => $r->payer_reference,
                'amountMinor' => (int) $r->amount_minor,
            ])->all();

        $refunds = DB::table('folio_refund_requests as r')
            ->leftJoin('folio_refund_disbursement_evidence as d', function ($join): void {
                $join->on('d.refund_request_id', '=', 'r.id')
                    ->on('d.hotel_id', '=', 'r.hotel_id');
            })
            ->where('r.hotel_id', $hotel->id)->where('r.folio_id', $folio->id)
            ->orderBy('r.id')->get(['r.id', 'r.payment_record_id', 'r.amount_minor',
                'r.reason', 'r.status', 'r.requested_by', 'r.reviewed_by',
                'd.id as evidence_id', 'd.status as evidence_status',
                'd.external_reference', 'd.recorded_by as evidence_recorded_by',
                'd.reviewed_by as evidence_reviewed_by'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'paymentId' => (string) $r->payment_record_id,
                'amountMinor' => (int) $r->amount_minor, 'reason' => $r->reason,
                'status' => $r->status,
                'requestedBy' => (string) $r->requested_by,
                'reviewedBy' => $r->reviewed_by === null ? null : (string) $r->reviewed_by,
                'evidenceRecordedBy' => $r->evidence_recorded_by === null ? null : (string) $r->evidence_recorded_by,
                'evidenceReviewedBy' => $r->evidence_reviewed_by === null ? null : (string) $r->evidence_reviewed_by,
                'evidenceId' => $r->evidence_id === null ? null : (string) $r->evidence_id,
                'evidenceStatus' => $r->evidence_status,
                'externalReference' => $r->external_reference,
            ])->all();

        $verified = 0;
        $pending = 0;
        foreach ($payments as $p) {
            // A manager-voided erroneous entry stays in audit history, but
            // cannot be treated as received money or a settlement blocker.
            if ($p['status'] === 'voided') {
                continue;
            }
            if ($p['status'] === 'staff_confirmed' && $p['verificationStatus'] === 'verified') {
                $verified += $p['amountMinor'];
            } else {
                $pending++;
            }
        }
        $allocated = 0;
        foreach ($allocations as $allocation) {
            $allocated += $allocation['amountMinor'];
        }
        $net = $summary['netRecordedChargesMinor'];
        $verifiedRefunds = 0;
        $unresolvedRefunds = 0;
        foreach ($refunds as $refund) {
            if (in_array($refund['status'], ['requested', 'approved'], true)) {
                if ($refund['status'] === 'approved' && $refund['evidenceStatus'] === 'verified') {
                    $verifiedRefunds += $refund['amountMinor'];
                } else {
                    $unresolvedRefunds++;
                }
            }
        }
        $unallocated = max(0, $verified - $allocated - $verifiedRefunds);
        $blockers = [];
        if ($net < 0) $blockers[] = 'Negative charges need a reviewed refund or correction.';
        if ($pending > 0) $blockers[] = 'Unverified or rejected payment evidence remains.';
        if ($net !== $allocated) $blockers[] = 'Verified payment allocations do not equal recorded charges.';
        if ($allocated > $verified) $blockers[] = 'Allocated funds exceed verified payment evidence.';
        if ($booking->lifecycle_state === 'no_show') {
            $blockers[] = 'No-show accounts use dedicated financial reconciliation, not guest checkout sign-off.';
        }
        if ($booking->lifecycle_state !== 'no_show' && config('financial_accounting.enable_live_folio_tax', false)) {
            $assessedEntries = array_column($taxAllocations, 'folioEntryId');
            foreach ($charges as $charge) {
                if ($charge['type'] === 'room_night'
                    && !in_array($charge['id'], $assessedEntries, true)) {
                    $blockers[] = 'Room-night tax allocations must be completed before settlement.';
                    break;
                }
            }
        }
        if ($unresolvedRefunds > 0) {
            $blockers[] = 'Outstanding refund requests require independently reviewed disbursement evidence.';
        }
        if ($unallocated > 0) {
            $blockers[] = 'Unallocated verified deposit remains; apply it or review a documented refund.';
        }
        if (Schema::hasTable('financial_folio_night_assessments')
            && DB::table('financial_folio_night_assessments')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('status', 'pending')->exists()) {
            $blockers[] = 'Pending room-night tax allocation must be reviewed.';
        }
        $signed = DB::table('folio_settlement_signoffs')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folio->id)->first();

        // Compare the CURRENT financial facts with the immutable snapshot.
        // Do not compare presentation-only fields such as "signed" or dates.
        $facts = [
            'charges' => $charges, 'adjustments' => $adjustments,
            'payments' => $payments, 'allocations' => $allocations,
            'refunds' => $refunds, 'taxAllocations' => $taxAllocations,
            'netRecordedChargesMinor' => $net,
            'verifiedPaymentsMinor' => $verified,
            'verifiedAllocatedMinor' => $allocated,
            'verifiedRefundsMinor' => $verifiedRefunds,
        ];
        $integrity = true;
        if ($signed !== null) {
            $saved = json_decode($signed->statement_snapshot, true);
            if (!is_array($saved)
                || !hash_equals($signed->snapshot_sha256,
                    hash('sha256', $signed->statement_snapshot))) {
                $integrity = false;
            } else {
                foreach ($facts as $field => $value) {
                    if (!array_key_exists($field, $saved) || $saved[$field] !== $value) {
                        $integrity = false;
                        break;
                    }
                }
            }
            if (!$integrity) {
                $blockers[] = 'Signed statement does not match the original immutable financial snapshot.';
            }
        }
        // Operational cashier status is presentation-only. NEVER include it in
        // signed financial facts: later shift reconciliation is not a folio edit.
        $cashShiftContexts = Schema::hasTable('cashier_shift_receipts')
            && Schema::hasTable('cashier_shifts')
            && Schema::hasColumn('cashier_shift_receipts', 'reservation_record_id')
            && Schema::hasColumn('cashier_shifts', 'cashier_id')
            && Schema::hasColumn('cashier_shifts', 'reviewed_by')
            ? DB::table('cashier_shift_receipts as receipt')
            ->join('cashier_shifts as shift', function ($join): void {
                $join->on('shift.id', '=', 'receipt.shift_id')
                    ->on('shift.hotel_id', '=', 'receipt.hotel_id');
            })
            ->leftJoin('users as cashier', 'cashier.id', '=', 'shift.cashier_id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'shift.reviewed_by')
            ->where('receipt.hotel_id', $hotel->id)
            ->where('receipt.reservation_record_id', $bookingId)
            ->get(['receipt.payment_id', 'shift.id as shift_id', 'shift.status',
                'shift.cashier_id', 'cashier.name as cashier_name',
                'shift.reviewed_by', 'reviewer.name as reviewer_name'])
            ->keyBy('payment_id') : collect();
        $paymentActors = Schema::hasTable('users')
            ? DB::table('folio_payment_records as payment')
            ->leftJoin('users as recorder', 'recorder.id', '=', 'payment.recorded_by')
            ->leftJoin('folio_settlement_verifications as verification', function ($join): void {
                $join->on('verification.payment_record_id', '=', 'payment.id')
                    ->on('verification.hotel_id', '=', 'payment.hotel_id');
            })
            ->leftJoin('users as preparer', 'preparer.id', '=', 'verification.prepared_by')
            ->leftJoin('users as payment_reviewer', 'payment_reviewer.id', '=', 'payment.reviewed_by')
            ->leftJoin('users as evidence_reviewer', 'evidence_reviewer.id', '=', 'verification.reviewed_by')
            ->where('payment.hotel_id', $hotel->id)
            ->where('payment.folio_id', $folio->id)
            ->get(['payment.id', 'payment.recorded_by', 'recorder.name as recorder_name',
                'preparer.name as preparer_name', 'payment_reviewer.name as payment_reviewer_name',
                'evidence_reviewer.name as evidence_reviewer_name'])
            ->keyBy('id') : collect();
        $paymentHandoff = array_values(array_map(function (array $payment) use ($cashShiftContexts, $paymentActors): array {
            $shift = $cashShiftContexts->get((int) $payment['id']);
            $actors = $paymentActors->get((int) $payment['id']);
            $status = $payment['method'] === 'cash'
                ? ($shift?->status ?? 'unlinked') : 'not_applicable';
            return [
                'paymentId' => $payment['id'],
                'recordedBy' => $actors === null ? null : (string) $actors->recorded_by,
                'recordedByName' => $actors?->recorder_name,
                'paymentReviewedByName' => $actors?->payment_reviewer_name,
                'evidenceReviewedByName' => $actors?->evidence_reviewer_name,
                'verificationPreparedByName' => $actors?->preparer_name,
                'cashShiftId' => $shift === null ? null : (string) $shift->shift_id,
                'cashierId' => $shift === null ? null : (string) $shift->cashier_id,
                'cashShiftStatus' => $status,
                'cashierName' => $shift?->cashier_name,
                'reconciledByName' => $shift?->reviewer_name,
                'reconciledBy' => $shift?->reviewed_by === null
                    ? null : (string) $shift->reviewed_by,
            ];
        }, $payments));

        $response = [
            'hotelId' => (string) $hotel->id,
            'bookingId' => (string) $bookingId,
            'folioId' => (string) $folio->id,
            'reservationReference' => $booking->reference,
            'checkIn' => $booking->check_in, 'checkOut' => $booking->check_out,
            'status' => $booking->lifecycle_state, 'currency' => $folio->currency,
            'charges' => $charges, 'adjustments' => $adjustments,
            'taxAllocations' => $taxAllocations,
            'payments' => $payments, 'cashShiftContexts' => $paymentHandoff,
            'allocations' => $allocations,
            'refunds' => $refunds, 'verifiedRefundsMinor' => $verifiedRefunds,
            'netRecordedChargesMinor' => $net,
            'verifiedPaymentsMinor' => $verified,
            'verifiedAllocatedMinor' => $allocated,
            'unallocatedVerifiedDepositsMinor' => $unallocated,
            'remainingMinor' => $net - $allocated,
            'blockers' => $blockers, 'canSignOff' => $blockers === [],
            'signed' => $signed !== null,
            'signedSnapshotIntegrity' => $integrity,
            'signedSnapshotSha256' => $signed?->snapshot_sha256,
            'signedAt' => $signed?->signed_at,
            'signedBy' => $signed === null ? null : (string) $signed->approved_by,
            'isTaxInvoice' => false, 'bankOrGatewayApiVerified' => false,
            'paymentEvidenceIndependentlyReviewed' => $pending === 0,
            'settlementWorkflowEnabled' => (bool) config('financial_accounting.enable_verified_settlement', false),
            'simplifiedCashierModeEnabled' => (bool) config('financial_accounting.simplified_cashier_mode', false),
        ];
        // Operational checkout is not an independently signed settlement.
        // Expose the original receipt evidence and its integrity only as
        // read-only audit metadata, never as immutable folio financial facts.
        $cashCheckout = Schema::hasTable('simplified_cash_checkout_audits')
            ? DB::table('simplified_cash_checkout_audits as record')
                ->leftJoin('users as actor', 'actor.id', '=', 'record.performed_by')
                ->where('record.hotel_id', $hotel->id)
                ->where('record.reservation_record_id', $bookingId)
                ->first(['record.*', 'actor.name as actor_name'])
            : null;
        $response['routineCashCheckoutAudit'] = $cashCheckout === null ? null : [
            'performedBy' => (string) $cashCheckout->performed_by,
            'performedByName' => $cashCheckout->actor_name,
            'checkedOutAt' => (string) $cashCheckout->checked_out_at,
            'recordedChargesMinor' => (int) $cashCheckout->recorded_charges_minor,
            'netCashMinor' => (int) $cashCheckout->net_cash_minor,
            'receiptCount' => (int) $cashCheckout->receipt_count,
            'integrityValid' => hash_equals((string) $cashCheckout->receipt_snapshot_sha256,
                hash('sha256', (string) $cashCheckout->receipt_snapshot)),
            'isIndependentFinancialSettlement' => false,
        ];
        $readiness = app(SimplifiedCashCheckoutPolicy::class)->readiness($hotel, $bookingId, $response);
        $response['routineCashCheckoutEligible'] = $readiness['eligible'];
        $response['routineCashCheckoutReason'] = $readiness['reason'];
        return $response;
    }

    private function assertUnsigned(object $folio): void
    {
        if (DB::table('folio_settlement_signoffs')->where('hotel_id', $folio->hotel_id)
            ->where('folio_id', $folio->id)->exists()) {
            throw ValidationException::withMessages(['settlement' => 'Signed folio is immutable; use a post-checkout adjustment workflow.']);
        }
    }

    private function verification(object $row): array
    {
        return [
            'id' => (string) $row->id, 'paymentId' => (string) $row->payment_record_id,
            'status' => $row->status, 'evidenceKind' => $row->evidence_kind,
            'evidenceReference' => $row->evidence_reference,
            'preparedBy' => (string) $row->prepared_by,
            'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
        ];
    }
}

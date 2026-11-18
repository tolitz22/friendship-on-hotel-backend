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
        app(ReadinessWriteGuard::class)->lockHotel($hotel);
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
            if ($allocated > (int) $payment->amount_minor
                || $amountMinor > (int) $payment->amount_minor - $allocated) {
                throw ValidationException::withMessages(['amountMinor' => 'Payment allocation exceeds verified payment amount.']);
            }
            if ($entryId !== null) {
                $entry = DB::table('folio_entries')->where('hotel_id', $hotel->id)
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
            if (!$statement['canSignOff']) {
                throw ValidationException::withMessages(['settlement' => implode(' ', $statement['blockers'])]);
            }
            $encoded = json_encode($statement, JSON_THROW_ON_ERROR);
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
        $charges = DB::table('folio_entries')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folio->id)->orderBy('id')
            ->get(['id', 'entry_type', 'description', 'stay_date', 'amount_minor'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'type' => $r->entry_type,
                'description' => $r->description, 'stayDate' => $r->stay_date,
                'amountMinor' => (int) $r->amount_minor,
            ])->all();
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
                'p.amount_minor', 'p.status', 'v.status as verification_status'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'method' => $r->method,
                'reference' => $r->reference, 'amountMinor' => (int) $r->amount_minor,
                'status' => $r->status, 'verificationStatus' => $r->verification_status,
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

        $verified = 0;
        $pending = 0;
        foreach ($payments as $p) {
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
        $blockers = [];
        if ($net < 0) $blockers[] = 'Negative charges need a reviewed refund or correction.';
        if ($pending > 0) $blockers[] = 'Unverified or rejected payment evidence remains.';
        if ($net !== $allocated) $blockers[] = 'Verified payment allocations do not equal recorded charges.';
        if ($allocated > $verified) $blockers[] = 'Allocated funds exceed verified payment evidence.';
        if (DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folio->id)->whereIn('status', ['requested', 'approved'])->exists()) {
            $blockers[] = 'Refund requests require separate confirmed-disbursement reconciliation.';
        }
        if (Schema::hasTable('financial_folio_night_assessments')
            && DB::table('financial_folio_night_assessments')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('status', 'pending')->exists()) {
            $blockers[] = 'Pending room-night tax allocation must be reviewed.';
        }
        $signed = DB::table('folio_settlement_signoffs')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folio->id)->first();
        return [
            'hotelId' => (string) $hotel->id,
            'bookingId' => (string) $bookingId,
            'folioId' => (string) $folio->id,
            'reservationReference' => $booking->reference,
            'checkIn' => $booking->check_in, 'checkOut' => $booking->check_out,
            'status' => $booking->lifecycle_state, 'currency' => $folio->currency,
            'charges' => $charges, 'adjustments' => $adjustments,
            'payments' => $payments, 'allocations' => $allocations,
            'netRecordedChargesMinor' => $net,
            'verifiedPaymentsMinor' => $verified,
            'verifiedAllocatedMinor' => $allocated,
            'unallocatedVerifiedDepositsMinor' => max(0, $verified - $allocated),
            'remainingMinor' => $net - $allocated,
            'blockers' => $blockers, 'canSignOff' => $blockers === [],
            'signed' => $signed !== null,
            'signedAt' => $signed?->signed_at,
            'signedBy' => $signed === null ? null : (string) $signed->approved_by,
            'isTaxInvoice' => false, 'bankOrGatewayApiVerified' => false,
            'paymentEvidenceIndependentlyReviewed' => true,
        ];
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

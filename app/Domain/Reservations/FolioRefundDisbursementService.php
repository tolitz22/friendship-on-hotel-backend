<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Documentary confirmation of an external bank refund, NEVER a payout API.
 * Only approved refunds of currently unallocated verified bank deposits can
 * be reconciled. Allocated charges cannot be silently refunded.
 */
final class FolioRefundDisbursementService
{
    public function index(Hotel $hotel, int $bookingId): array
    {
        $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId);
        return DB::table('folio_refund_disbursement_evidence')
            ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
            ->orderBy('id')->get()->map(fn ($row) => $this->present($row))->all();
    }

    public function record(Hotel $hotel, int $bookingId, int $refundId, int $actorId, string $reference): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $refundId, $actorId, $reference): array {
            $folio = $this->lock($hotel, $bookingId);
            $refund = DB::table('folio_refund_requests')
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->where('id', $refundId)->lockForUpdate()->first();
            abort_unless($refund !== null, 404);
            if ($refund->status !== 'approved') {
                throw ValidationException::withMessages(['refund' => 'Only approved refunds may receive disbursement evidence.']);
            }
            $existing = DB::table('folio_refund_disbursement_evidence')
                ->where('hotel_id', $hotel->id)->where('refund_request_id', $refundId)->first();
            if ($existing !== null) {
                if ($existing->external_reference !== trim($reference)) {
                    throw ValidationException::withMessages(['reference' => 'Different evidence already recorded for this refund.']);
                }
                return $this->present($existing);
            }
            if (mb_strlen(trim($reference)) < 8 || mb_strlen($reference) > 160) {
                throw ValidationException::withMessages(['reference' => 'External bank refund reference is required.']);
            }
            $payment = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('id', $refund->payment_record_id)->first();
            if ($payment === null || $payment->method !== 'bank_transfer'
                || $payment->status !== 'staff_confirmed'
                || !DB::table('folio_settlement_verifications')
                    ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                    ->where('payment_record_id', $payment->id)->where('status', 'verified')->exists()) {
                throw ValidationException::withMessages(['refund' => 'Only independently reviewed bank deposits can be reconciled.']);
            }
            $allocated = (int) DB::table('folio_settlement_allocations')
                ->where('hotel_id', $hotel->id)->where('payment_record_id', $payment->id)
                ->sum('amount_minor');
            $otherRefunds = (int) DB::table('folio_refund_disbursement_evidence')
                ->where('hotel_id', $hotel->id)->where('payment_record_id', $payment->id)
                ->whereIn('status', ['pending', 'verified'])->sum('amount_minor');
            if ($allocated > (int) $payment->amount_minor
                || $otherRefunds > (int) $payment->amount_minor - $allocated
                || (int) $refund->amount_minor > (int) $payment->amount_minor - $allocated - $otherRefunds) {
                throw ValidationException::withMessages(['refund' => 'Refund exceeds unallocated verified deposit.']);
            }
            $id = DB::table('folio_refund_disbursement_evidence')->insertGetId([
                'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                'refund_request_id' => $refundId, 'payment_record_id' => $payment->id,
                'amount_minor' => $refund->amount_minor, 'external_reference' => trim($reference),
                'status' => 'pending', 'recorded_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->present(DB::table('folio_refund_disbursement_evidence')->find($id));
        }, 3);
    }

    public function review(Hotel $hotel, int $bookingId, int $evidenceId, int $reviewerId,
        string $decision, string $note): array
    {
        return DB::transaction(function () use (
            $hotel, $bookingId, $evidenceId, $reviewerId, $decision, $note
        ): array {
            $folio = $this->lock($hotel, $bookingId);
            $evidence = DB::table('folio_refund_disbursement_evidence')
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->where('id', $evidenceId)->lockForUpdate()->first();
            abort_unless($evidence !== null, 404);
            if ($evidence->status !== 'pending' || !in_array($decision, ['verify', 'reject'], true)
                || mb_strlen(trim($note)) < 10) {
                throw ValidationException::withMessages(['evidence' => 'Pending evidence and a substantive review are required.']);
            }
            if ($decision === 'verify' && (int) $evidence->recorded_by === $reviewerId) {
                throw ValidationException::withMessages(['reviewer' => 'A second administrator must confirm external refund evidence.']);
            }
            $refund = DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('id', $evidence->refund_request_id)->first();
            if ($refund === null || $refund->status !== 'approved') {
                throw ValidationException::withMessages(['refund' => 'Original refund approval is no longer valid.']);
            }
            DB::table('folio_refund_disbursement_evidence')->where('id', $evidenceId)
                ->where('hotel_id', $hotel->id)->update([
                    'status' => $decision === 'verify' ? 'verified' : 'rejected',
                    'reviewed_by' => $reviewerId, 'review_note' => trim($note),
                    'reviewed_at' => now(), 'updated_at' => now(),
                ]);
            return $this->present(DB::table('folio_refund_disbursement_evidence')->find($evidenceId));
        }, 3);
    }

    private function lock(Hotel $hotel, int $bookingId): object
    {
        if (!config('financial_accounting.enable_verified_settlement', false)) {
            throw ValidationException::withMessages(['activation' => 'Verified settlement is not activated.']);
        }
        app(ReadinessWriteGuard::class)->lockHotel($hotel);
        $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
        app(FolioAccountingService::class)->assertNotSigned($hotel, $folio);
        return $folio;
    }

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id, 'refundRequestId' => (string) $row->refund_request_id,
            'paymentId' => (string) $row->payment_record_id,
            'amountMinor' => (int) $row->amount_minor, 'externalReference' => $row->external_reference,
            'status' => $row->status, 'recordedBy' => (string) $row->recorded_by,
            'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
            'fundsSentByPms' => false, 'bankOrGatewayApiVerified' => false,
        ];
    }
}

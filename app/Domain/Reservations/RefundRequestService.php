<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Manual refund REQUEST and APPROVAL registry, not a refund processor.
 * No endpoint here can mark funds as paid out.
 */
final class RefundRequestService
{
    public function index(Hotel $hotel, int $bookingId): array
    {
        $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId);
        return DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folio->id)->orderBy('id')->get()
            ->map(fn (object $r): array => $this->present($r))->all();
    }

    public function create(Hotel $hotel, int $bookingId, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actor, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
            app(FolioAccountingService::class)->assertNotSigned($hotel, $folio);
            $fingerprint = hash('sha256', json_encode([
                $bookingId, (int) $input['paymentRecordId'],
                (int) $input['amountMinor'], trim($input['reason']),
            ], JSON_THROW_ON_ERROR));

            $previous = DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)
                ->where('request_key', $input['requestKey'])->first();
            if ($previous !== null) {
                if ($previous->folio_id != $folio->id || ! hash_equals($previous->payload_sha256, $fingerprint)) {
                    throw new HttpException(409, 'Refund request key already used with different details.');
                }
                return $this->present($previous) + ['replayed' => true];
            }

            $payment = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('id', $input['paymentRecordId'])->first();
            abort_unless($payment !== null, 404);
            if ($payment->status !== 'staff_confirmed') {
                throw new HttpException(409, 'Only staff-confirmed payments may be considered for refund review.');
            }

            $reserved = DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)
                ->where('payment_record_id', $payment->id)
                ->whereIn('status', ['requested', 'approved'])->sum('amount_minor');
            $allocated = config('financial_accounting.enable_verified_settlement', false)
                ? (int) DB::table('folio_settlement_allocations')
                    ->where('hotel_id', $hotel->id)
                    ->where('payment_record_id', $payment->id)->sum('amount_minor')
                : 0;
            if ($allocated > (int) $payment->amount_minor
                || (int) $reserved > (int) $payment->amount_minor - $allocated
                || (int) $input['amountMinor'] > (int) $payment->amount_minor - $allocated - (int) $reserved) {
                throw new HttpException(409, 'Refund review amounts exceed recorded payment evidence.');
            }

            $this->assertNoShowEligibility($hotel, $bookingId, (int) $payment->id, (int) $input['amountMinor']);

            $id = DB::table('folio_refund_requests')->insertGetId([
                'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                'payment_record_id' => $payment->id,
                'request_key' => $input['requestKey'], 'payload_sha256' => $fingerprint,
                'amount_minor' => $input['amountMinor'], 'reason' => trim($input['reason']),
                'status' => 'requested', 'requested_by' => $actor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->present(DB::table('folio_refund_requests')->find($id)) + ['replayed' => false];
        }, 3);
    }

    public function review(Hotel $hotel, int $bookingId, int $requestId, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $requestId, $actor, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
            app(FolioAccountingService::class)->assertNotSigned($hotel, $folio);
            $request = DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('id', $requestId)
                ->lockForUpdate()->first();
            abort_unless($request !== null, 404);
            if ($request->status !== 'requested') {
                throw new HttpException(409, 'Refund request was already reviewed.');
            }
            if ((int) $request->requested_by === $actor && $input['decision'] === 'approve') {
                throw new HttpException(403, 'Refund request approval requires a different administrator.');
            }
            if ($input['decision'] === 'approve') {
                $this->assertNoShowEligibility($hotel, $bookingId, (int) $request->payment_record_id,
                    (int) $request->amount_minor, (int) $request->id);
            }
            $next = $input['decision'] === 'approve' ? 'approved' : 'rejected';
            DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)
                ->where('id', $requestId)->update([
                    'status' => $next, 'reviewed_by' => $actor,
                    'review_note' => trim($input['note']), 'updated_at' => now(),
                ]);
            return $this->present(DB::table('folio_refund_requests')->find($requestId));
        }, 3);
    }

    private function assertNoShowEligibility(Hotel $hotel, int $bookingId, int $paymentId, int $amount, ?int $requestId = null): void
    {
        if (!DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('id', $bookingId)->where('lifecycle_state', 'no_show')->exists()) {
            return;
        }
        $eligibility = app(NoShowRefundEligibility::class)->review($hotel, $bookingId, $requestId);
        $limit = 0;
        foreach ($eligibility['payments'] as $payment) {
            if ($payment['paymentId'] === (string) $paymentId) {
                $limit = $payment['maximumRequestMinor'];
                break;
            }
        }
        if (!$eligibility['canRequestRefund'] || $amount < 1 || $amount > $limit) {
            throw new HttpException(409, 'No-show refund exceeds verified excess funds or financial clearance is incomplete.');
        }
    }

    private function present(object $r): array
    {
        return [
            'id' => (string) $r->id,
            'paymentRecordId' => (string) $r->payment_record_id,
            'amountMinor' => (int) $r->amount_minor,
            'reason' => $r->reason,
            'status' => $r->status,
            'requestedBy' => (string) $r->requested_by,
            'reviewedBy' => $r->reviewed_by === null ? null : (string) $r->reviewed_by,
            'reviewNote' => $r->review_note,
            'fundsDisbursed' => false,
            'bankOrGatewayVerified' => false,
        ];
    }
}

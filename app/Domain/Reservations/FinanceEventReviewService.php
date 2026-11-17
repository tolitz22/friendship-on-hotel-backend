<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Administrative cross-check of signed event observations against local staff
 * payment evidence. A match does NOT imply payment settled or bank verification.
 */
final class FinanceEventReviewService
{
    public function index(Hotel $hotel, int $bookingId): array
    {
        $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId);
        return DB::table('finance_gateway_event_reviews as reviews')
            ->join('folio_payment_records as payments', 'payments.id', '=', 'reviews.payment_record_id')
            ->join('finance_gateway_events as events', 'events.id', '=', 'reviews.event_id')
            ->where('events.hotel_id', $hotel->id)
            ->where('reviews.hotel_id', $hotel->id)
            ->where('payments.hotel_id', $hotel->id)
            ->where('payments.folio_id', $folio->id)
            ->orderBy('reviews.id')
            ->get(['reviews.id', 'reviews.assessment', 'reviews.note', 'reviews.actor_id',
                'events.event_id', 'events.event_type', 'events.provider', 'events.created_at'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'providerEventId' => $row->event_id, 'provider' => $row->provider,
                'eventType' => $row->event_type, 'assessment' => $row->assessment,
                'note' => $row->note, 'actorId' => (string) $row->actor_id,
                'createdAt' => $row->created_at,
                'bankOrGatewayVerified' => false, 'isSettled' => false,
            ])->all();
    }

    public function match(Hotel $hotel, int $bookingId, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actor, $input): array {
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
            $payment = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('id', $input['paymentRecordId'])->first();
            abort_unless($payment !== null, 404);

            $event = DB::table('finance_gateway_events')->where('id', $input['eventId'])
                ->lockForUpdate()->first();
            abort_unless($event !== null, 404);
            if ((int) $event->hotel_id !== (int) $hotel->id) {
                throw new HttpException(404, 'Finance event does not belong to this hotel.');
            }
            if ($event->provider !== 'custom_hmac_v1' || $event->event_type !== 'payment.observed') {
                throw new HttpException(409, 'Event does not represent an observed payment.');
            }

            if (DB::table('finance_gateway_event_reviews')->where('event_id', $event->id)->exists()) {
                throw new HttpException(409, 'Event has already been reviewed.');
            }

            $payload = json_decode($event->payload_json, true, flags: JSON_THROW_ON_ERROR);
            $same = hash_equals((string) $payment->reference, (string) $payload['reference'])
                && (int) $payment->amount_minor === (int) $payload['amountMinor']
                && $folio->currency === $payload['currency'];
            if (! $same) {
                throw new HttpException(409, 'Event reference, amount and currency must match the folio payment record.');
            }

            $id = DB::table('finance_gateway_event_reviews')->insertGetId([
                'event_id' => $event->id, 'hotel_id' => $hotel->id,
                'payment_record_id' => $payment->id, 'actor_id' => $actor,
                'assessment' => 'reference_match', 'note' => trim($input['note']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('finance_gateway_events')->where('id', $event->id)
                ->update(['processing_status' => 'reference_matched', 'updated_at' => now()]);

            return [
                'id' => (string) $id, 'assessment' => 'reference_match',
                'paymentRecordId' => (string) $payment->id,
                'providerEventId' => $event->event_id,
                'bankOrGatewayVerified' => false,
                'isSettled' => false,
            ];
        }, 3);
    }
}

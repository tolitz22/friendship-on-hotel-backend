<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Conservative room-type inventory writer. All application booking mutations
 * must take the same stable room-type row lock on MySQL/InnoDB and PostgreSQL.
 */
final class ReservationBookingService
{
    public function create(Hotel $hotel, int $actorId, array $data): array
    {
        $period = new StayPeriod($data['checkIn'], $data['checkOut']);
        if ($period->nights() < (int) config('booking.minimum_nights', 1)
            || $period->nights() > (int) config('booking.maximum_nights', 31)) {
            throw new HttpException(422, 'Stay must be between 1 and 31 nights.');
        }

        // Keep the original hash byte-for-byte for legacy unpriced retries.
        // Include the selected plan only when this is a priced booking.
        $fingerprintFields = [
            'guestId' => (int) $data['guestId'],
            'roomTypeId' => (int) $data['roomTypeId'],
            'checkIn' => $period->arrival,
            'checkOut' => $period->departure,
        ];
        if (isset($data['ratePlanId'])) {
            $fingerprintFields['ratePlanId'] = (int) $data['ratePlanId'];
        }
        $fingerprint = hash('sha256', json_encode($fingerprintFields, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($hotel, $actorId, $data, $period, $fingerprint): array {
            // Lock a stable parent row, not a possibly empty set of reservation rows.
            $type = DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $data['roomTypeId'])->lockForUpdate()->first();
            abort_unless($type !== null, 404);

            $previous = DB::table('reservation_booking_requests')
                ->where('hotel_id', $hotel->id)
                ->where('idempotency_key', $data['idempotencyKey'])->first();
            if ($previous !== null) {
                if (! hash_equals($previous->payload_sha256, $fingerprint)) {
                    throw new HttpException(409, 'Idempotency key already used for a different booking.');
                }

                return $this->present($hotel->id, (int) $previous->reservation_record_id, true);
            }

            abort_unless(DB::table('guests')->where('hotel_id', $hotel->id)
                ->where('id', $data['guestId'])->exists(), 404);

            // The same room-type row is locked above. Availability and room
            // blocks are evaluated under this lock before making the sale.
            $availability = app(RoomInventoryService::class)->availability(
                $hotel, (int) $type->id, $period
            );
            if (! $availability['canBook']) {
                throw new HttpException(409, 'No inventory remains for every requested night.');
            }

            // Optional price snapshot: existing legacy/unpriced bookings stay
            // supported and are never assigned an invented nightly rate.
            $quote = isset($data['ratePlanId'])
                ? app(RoomRateQuoteService::class)->quote(
                    $hotel, (int) $type->id, (int) $data['ratePlanId'], $period
                )
                : null;

            $id = DB::table('reservation_records')->insertGetId([
                'hotel_id' => $hotel->id,
                'room_type_id' => $type->id,
                'guest_id' => $data['guestId'],
                'assigned_room_id' => null,
                'reference' => 'BK-'.Str::upper(Str::random(16)),
                'check_in' => $period->arrival,
                'check_out' => $period->departure,
                'lifecycle_state' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('reservation_booking_requests')->insert([
                'hotel_id' => $hotel->id,
                'idempotency_key' => $data['idempotencyKey'],
                'payload_sha256' => $fingerprint,
                'reservation_record_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($quote !== null) {
                $folioId = DB::table('reservation_folios')->insertGetId([
                    'hotel_id' => $hotel->id,
                    'reservation_record_id' => $id,
                    'currency' => $quote['currency'],
                    'created_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($quote['nights'] as $night) {
                    DB::table('folio_entries')->insert([
                        'folio_id' => $folioId, 'hotel_id' => $hotel->id,
                        'entry_type' => 'room_night',
                        'description' => 'Room night '.$night,
                        'amount_minor' => $quote['nightlyMinor'],
                        'rate_plan_id' => $data['ratePlanId'],
                        'stay_date' => $night,
                        'actor_id' => $actorId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $this->audit($hotel->id, $id, $actorId, 'created', null, 'confirmed');

            return $this->present($hotel->id, $id, false);
        }, 3);
    }

    public function cancel(Hotel $hotel, int $actorId, int $reservationId): array
    {
        return DB::transaction(function () use ($hotel, $actorId, $reservationId): array {
            $record = DB::table('reservation_records')
                ->where('hotel_id', $hotel->id)->where('id', $reservationId)->first();
            abort_unless($record !== null, 404);

            // Lock in the same order as create (room type, then reservation).
            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $record->room_type_id)->lockForUpdate()->first();
            $record = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $reservationId)->lockForUpdate()->first();
            abort_unless($record !== null, 404);

            if ($record->lifecycle_state !== 'confirmed') {
                throw new HttpException(409, 'Only confirmed reservations can be cancelled through this endpoint.');
            }

            if (DB::table('reservation_folios')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $record->id)->exists()) {
                throw new HttpException(409, 'Priced reservations require a financial reversal policy before cancellation.');
            }

            // This starter workflow does not compute refunds or cancellation fees.
            DB::table('reservation_records')->where('id', $record->id)->update([
                'lifecycle_state' => 'cancelled', 'updated_at' => now(),
            ]);
            $this->audit($hotel->id, (int) $record->id, $actorId, 'cancelled', 'confirmed', 'cancelled');

            return $this->present($hotel->id, (int) $record->id, false);
        }, 3);
    }

    private function audit(int $hotelId, int $id, int $actorId, string $event, ?string $from, string $to): void
    {
        DB::table('reservation_booking_events')->insert([
            'hotel_id' => $hotelId,
            'reservation_record_id' => $id,
            'actor_id' => $actorId,
            'event_type' => $event,
            'old_state' => $from,
            'new_state' => $to,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function present(int $hotelId, int $id, bool $replayed): array
    {
        $row = DB::table('reservation_records')
            ->where('hotel_id', $hotelId)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return [
            'id' => (string) $row->id,
            'reference' => $row->reference,
            'hotelId' => (string) $row->hotel_id,
            'guestId' => (string) $row->guest_id,
            'roomTypeId' => (string) $row->room_type_id,
            'assignedRoomId' => $row->assigned_room_id === null ? null : (string) $row->assigned_room_id,
            'checkIn' => $row->check_in,
            'checkOut' => $row->check_out,
            'status' => $row->lifecycle_state,
            'idempotentReplay' => $replayed,
        ];
    }
}

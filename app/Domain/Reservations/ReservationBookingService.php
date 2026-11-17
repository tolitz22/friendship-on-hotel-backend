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

        $fingerprint = hash('sha256', json_encode([
            'guestId' => (int) $data['guestId'],
            'roomTypeId' => (int) $data['roomTypeId'],
            'checkIn' => $period->arrival,
            'checkOut' => $period->departure,
        ], JSON_THROW_ON_ERROR));

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

            $capacity = DB::table('rooms')->where('hotel_id', $hotel->id)
                ->where('room_type_id', $type->id)->count();
            if ($capacity < 1) {
                throw new HttpException(409, 'This room type has no configured rooms.');
            }

            $overlapping = DB::table('reservation_records')
                ->where('hotel_id', $hotel->id)
                ->where('room_type_id', $type->id)
                ->where('check_in', '<', $period->departure)
                ->where('check_out', '>', $period->arrival)
                // Fail closed for provisional/unknown states: only explicitly
                // released states stop consuming inventory.
                ->where(function ($query): void {
                    $query->whereNull('lifecycle_state')
                        ->orWhereNotIn('lifecycle_state', config('booking.released_states', ['cancelled', 'checked_out', 'no_show']));
                })
                ->get(['check_in', 'check_out']);

            // Check EACH night, not the count of overlapping stays across the whole period.
            $arrival = new \DateTimeImmutable($period->arrival);
            for ($offset = 0; $offset < $period->nights(); $offset++) {
                $night = $arrival->modify('+'.$offset.' days')->format('Y-m-d');
                $used = $overlapping->filter(
                    fn (object $record): bool => $record->check_in <= $night && $record->check_out > $night
                )->count();
                if ($used >= $capacity) {
                    throw new HttpException(409, 'No inventory remains for every requested night.');
                }
            }

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

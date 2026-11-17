<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Nightly room-type inventory ledger backed by catalogued physical rooms.
 * Callers writing blocks, assignments or reservations MUST lock room_types first.
 */
final class RoomInventoryService
{
    public function availability(Hotel $hotel, int $typeId, StayPeriod $stay): array
    {
        $type = DB::table('room_types')->where('hotel_id', $hotel->id)->where('id', $typeId)->first();
        abort_unless($type !== null, 404);

        $capacity = DB::table('rooms')->where('hotel_id', $hotel->id)->where('room_type_id', $typeId)->count();

        $bookings = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->where('room_type_id', $typeId)
            ->where('check_in', '<', $stay->departure)->where('check_out', '>', $stay->arrival)
            ->where(function ($query): void {
                $query->whereNull('lifecycle_state')
                    ->orWhereNotIn('lifecycle_state', config('booking.released_states', ['cancelled', 'checked_out', 'no_show']));
            })->get(['id', 'check_in', 'check_out']);

        $blocks = DB::table('room_inventory_blocks')
            ->where('hotel_id', $hotel->id)->where('room_type_id', $typeId)
            ->where('is_active', true)->where('check_in', '<', $stay->departure)
            ->where('check_out', '>', $stay->arrival)
            ->get(['room_id', 'check_in', 'check_out']);

        $days = [];
        $arrival = new \DateTimeImmutable($stay->arrival);
        for ($i = 0; $i < $stay->nights(); $i++) {
            $day = $arrival->modify('+'.$i.' days')->format('Y-m-d');
            $booked = $bookings->filter(fn (object $r): bool => $r->check_in <= $day && $day < $r->check_out)->count();
            // Multiple overlapping blocks for the same physical room consume one room.
            $blocked = $blocks->filter(fn (object $b): bool => $b->check_in <= $day && $day < $b->check_out)
                ->pluck('room_id')->unique()->count();
            $days[] = [
                'date' => $day,
                'physicalRooms' => $capacity,
                'reserved' => $booked,
                'blocked' => $blocked,
                'remaining' => max(0, $capacity - $booked - $blocked),
                'overcommitted' => ($booked + $blocked) > $capacity,
            ];
        }

        return [
            'hotelId' => (string) $hotel->id,
            'roomTypeId' => (string) $typeId,
            'checkIn' => $stay->arrival,
            'checkOut' => $stay->departure,
            'nights' => $days,
            'canBook' => $days !== [] && collect($days)->every(fn (array $day): bool => $day['remaining'] > 0),
            'includesMaintenanceBlocks' => true,
            'includesHousekeepingReadiness' => false,
            'includesPricing' => false,
        ];
    }

    /**
     * Advisory room choices for a confirmed booking. Conflict checks are
     * repeated transactionally by assign(); this read cannot reserve a room.
     */
    public function assignmentOptions(Hotel $hotel, int $bookingId, int $page, int $perPage): array
    {
        $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('id', $bookingId)->first();
        abort_unless($booking !== null, 404);

        $roomTypeName = DB::table('room_types')
            ->where('hotel_id', $hotel->id)->where('id', $booking->room_type_id)
            ->value('name');
        $currentRoomNumber = $booking->assigned_room_id === null ? null
            : DB::table('rooms')->where('hotel_id', $hotel->id)
                ->where('id', $booking->assigned_room_id)->value('number');

        $rooms = DB::table('rooms')->where('hotel_id', $hotel->id)
            ->where('room_type_id', $booking->room_type_id)
            ->orderBy('number')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        $ids = $rooms->getCollection()->pluck('id')->all();
        $blockedIds = $ids === [] ? [] : DB::table('room_inventory_blocks')
            ->where('hotel_id', $hotel->id)->whereIn('room_id', $ids)
            ->where('is_active', true)
            ->where('check_in', '<', $booking->check_out)
            ->where('check_out', '>', $booking->check_in)
            ->pluck('room_id')->map(fn ($id): string => (string) $id)->all();
        $occupiedIds = $ids === [] ? [] : DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->whereIn('assigned_room_id', $ids)
            ->where('id', '!=', $bookingId)
            ->where('check_in', '<', $booking->check_out)
            ->where('check_out', '>', $booking->check_in)
            ->where(function ($query): void {
                $query->whereNull('lifecycle_state')
                    ->orWhereNotIn('lifecycle_state', config('booking.released_states',
                        ['cancelled', 'checked_out', 'no_show']));
            })->pluck('assigned_room_id')->map(fn ($id): string => (string) $id)->all();

        return [
            'data' => $rooms->getCollection()->map(function (object $room) use ($booking, $blockedIds, $occupiedIds): array {
                $id = (string) $room->id;
                $reason = in_array($id, $blockedIds, true) ? 'maintenance_block'
                    : (in_array($id, $occupiedIds, true) ? 'reservation_conflict' : null);
                return [
                    'id' => $id,
                    'number' => $room->number,
                    'floor' => $room->floor_number === null ? null : (int) $room->floor_number,
                    'roomTypeId' => (string) $room->room_type_id,
                    'status' => $reason === null ? 'available' : 'unavailable',
                    'reason' => $reason,
                    'current' => (int) $booking->assigned_room_id === (int) $room->id,
                ];
            })->values(),
            'meta' => [
                'hotelId' => (string) $hotel->id,
                'bookingId' => (string) $bookingId,
                'roomTypeId' => (string) $booking->room_type_id,
                'roomTypeName' => (string) $roomTypeName,
                'currentRoomNumber' => $currentRoomNumber,
                'checkIn' => $booking->check_in,
                'checkOut' => $booking->check_out,
                'page' => $rooms->currentPage(), 'perPage' => $rooms->perPage(),
                'total' => $rooms->total(), 'totalPages' => $rooms->lastPage(),
                'advisoryOnly' => true,
                'readinessVerified' => false,
            ],
        ];
    }

    public function block(Hotel $hotel, int $actor, array $data): array
    {
        $stay = $this->stay($data['checkIn'], $data['checkOut']);

        return DB::transaction(function () use ($hotel, $actor, $data, $stay): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $room = DB::table('rooms')->where('hotel_id', $hotel->id)->where('id', $data['roomId'])->first();
            abort_unless($room !== null, 404);
            DB::table('room_types')->where('hotel_id', $hotel->id)->where('id', $room->room_type_id)
                ->lockForUpdate()->first();

            // Do not block an assigned room with an existing consuming reservation.
            $collision = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('assigned_room_id', $room->id)
                ->where('check_in', '<', $stay->departure)->where('check_out', '>', $stay->arrival)
                ->where(function ($query): void {
                    $query->whereNull('lifecycle_state')
                        ->orWhereNotIn('lifecycle_state', config('booking.released_states', ['cancelled', 'checked_out', 'no_show']));
                })->exists();
            if ($collision) {
                throw new HttpException(409, 'Room has an assigned reservation in this period.');
            }

            // Overlapping blocks of the same room are redundant; reject them.
            $duplicate = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)
                ->where('room_id', $room->id)->where('is_active', true)
                ->where('check_in', '<', $stay->departure)->where('check_out', '>', $stay->arrival)->exists();
            if ($duplicate) {
                throw new HttpException(409, 'Room is already blocked during these nights.');
            }

            $before = $this->availability($hotel, (int) $room->room_type_id, $stay);
            foreach ($before['nights'] as $night) {
                if ($night['remaining'] < 1) {
                    throw new HttpException(409, 'Block would overcommit existing room-type reservations.');
                }
            }

            $id = DB::table('room_inventory_blocks')->insertGetId([
                'hotel_id' => $hotel->id, 'room_type_id' => $room->room_type_id, 'room_id' => $room->id,
                'check_in' => $stay->arrival, 'check_out' => $stay->departure,
                'reason' => trim($data['reason']), 'is_active' => true,
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['id' => (string) $id, 'roomId' => (string) $room->id, 'active' => true];
        }, 3);
    }

    public function release(Hotel $hotel, int $actor, int $blockId): array
    {
        return DB::transaction(function () use ($hotel, $actor, $blockId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $block = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)->where('id', $blockId)->first();
            abort_unless($block !== null, 404);
            DB::table('room_types')->where('hotel_id', $hotel->id)->where('id', $block->room_type_id)
                ->lockForUpdate()->first();
            $block = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)
                ->where('id', $blockId)->lockForUpdate()->first();
            if (! $block->is_active) {
                throw new HttpException(409, 'Block already released.');
            }
            DB::table('room_inventory_blocks')->where('id', $blockId)->update([
                'is_active' => false, 'released_by' => $actor, 'updated_at' => now(),
            ]);
            return ['id' => (string) $blockId, 'active' => false];
        }, 3);
    }

    public function assign(Hotel $hotel, int $actor, int $bookingId, int $roomId): array
    {
        return DB::transaction(function () use ($hotel, $actor, $bookingId, $roomId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->first();
            abort_unless($booking !== null, 404);

            DB::table('room_types')->where('hotel_id', $hotel->id)->where('id', $booking->room_type_id)
                ->lockForUpdate()->first();
            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->lockForUpdate()->first();
            if ($booking->lifecycle_state !== 'confirmed') {
                throw new HttpException(409, 'Only confirmed reservations may be assigned.');
            }

            $room = DB::table('rooms')->where('hotel_id', $hotel->id)
                ->where('room_type_id', $booking->room_type_id)->where('id', $roomId)->first();
            abort_unless($room !== null, 404);

            $blocked = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)
                ->where('room_id', $roomId)->where('is_active', true)
                ->where('check_in', '<', $booking->check_out)->where('check_out', '>', $booking->check_in)->exists();
            if ($blocked) {
                throw new HttpException(409, 'The selected room is blocked during this stay.');
            }

            $collision = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('assigned_room_id', $roomId)->where('id', '!=', $bookingId)
                ->where('check_in', '<', $booking->check_out)->where('check_out', '>', $booking->check_in)
                ->where(function ($query): void {
                    $query->whereNull('lifecycle_state')
                        ->orWhereNotIn('lifecycle_state', config('booking.released_states', ['cancelled', 'checked_out', 'no_show']));
                })->exists();
            if ($collision) {
                throw new HttpException(409, 'Room is already assigned during these nights.');
            }

            if ((int) $booking->assigned_room_id === $roomId) {
                return ['bookingId' => (string) $bookingId, 'roomId' => (string) $roomId, 'unchanged' => true];
            }
            DB::table('reservation_records')->where('id', $bookingId)->update([
                'assigned_room_id' => $roomId, 'updated_at' => now(),
            ]);
            DB::table('reservation_booking_events')->insert([
                'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
                'actor_id' => $actor, 'event_type' => 'room_assigned',
                'old_state' => $booking->lifecycle_state, 'new_state' => $booking->lifecycle_state,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['bookingId' => (string) $bookingId, 'roomId' => (string) $roomId, 'unchanged' => false];
        }, 3);
    }

    public function stay(string $from, string $to): StayPeriod
    {
        $stay = new StayPeriod($from, $to);
        if ($stay->nights() > (int) config('booking.maximum_nights', 31)) {
            throw new HttpException(422, 'Stay exceeds maximum supported nights.');
        }
        return $stay;
    }
}

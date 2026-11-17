<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 22: explicit front desk transitions. Both transitions are admin-only
 * at the HTTP boundary; status changes and events are committed atomically.
 */
final class ReservationStayLifecycleService
{
    public function transition(Hotel $hotel, int $actor, int $bookingId, string $operation): array
    {
        if (! in_array($operation, ['check_in', 'check_out'], true)) {
            throw new \InvalidArgumentException('Unsupported reservation transition.');
        }

        return DB::transaction(function () use ($hotel, $actor, $bookingId, $operation): array {
            $snapshot = DB::table('reservation_records')
                ->where('hotel_id', $hotel->id)->where('id', $bookingId)->first();
            abort_unless($snapshot !== null, 404);

            // Stable room-type lock first, consistent with creation, cancellation,
            // assignment and inventory-block mutations.
            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $snapshot->room_type_id)->lockForUpdate()->firstOrFail();

            $record = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($record !== null, 404);

            $expected = $operation === 'check_in' ? 'confirmed' : 'checked_in';
            $next = $operation === 'check_in' ? 'checked_in' : 'checked_out';
            if ($record->lifecycle_state !== $expected) {
                throw new HttpException(409, 'This reservation cannot perform the requested transition from its current state.');
            }
            if ($operation === 'check_in') {
                if ($record->assigned_room_id === null) {
                    throw new HttpException(409, 'Assign a physical room before check-in.');
                }

                // Existing assignments must still be safe at the time of check-in.
                $blocked = DB::table('room_inventory_blocks')
                    ->where('hotel_id', $hotel->id)
                    ->where('room_id', $record->assigned_room_id)
                    ->where('is_active', true)
                    ->where('check_in', '<', $record->check_out)
                    ->where('check_out', '>', $record->check_in)->exists();
                if ($blocked) {
                    throw new HttpException(409, 'The assigned room has an active inventory block.');
                }

                $collision = DB::table('reservation_records')
                    ->where('hotel_id', $hotel->id)
                    ->where('assigned_room_id', $record->assigned_room_id)
                    ->where('id', '!=', $record->id)
                    ->where('check_in', '<', $record->check_out)
                    ->where('check_out', '>', $record->check_in)
                    ->where(function ($query): void {
                        $query->whereNull('lifecycle_state')->orWhereNotIn(
                            'lifecycle_state',
                            config('booking.released_states', ['cancelled', 'checked_out', 'no_show'])
                        );
                    })->exists();
                if ($collision) {
                    throw new HttpException(409, 'The assigned room has a conflicting reservation.');
                }
            }

            DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $record->id)->update(['lifecycle_state' => $next, 'updated_at' => now()]);
            DB::table('reservation_booking_events')->insert([
                'hotel_id' => $hotel->id,
                'reservation_record_id' => $record->id,
                'actor_id' => $actor,
                'event_type' => $operation,
                'old_state' => $expected,
                'new_state' => $next,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => (string) $record->id,
                'reference' => $record->reference,
                'status' => $next,
                'assignedRoomId' => $record->assigned_room_id === null
                    ? null : (string) $record->assigned_room_id,
            ];
        }, 3);
    }
}

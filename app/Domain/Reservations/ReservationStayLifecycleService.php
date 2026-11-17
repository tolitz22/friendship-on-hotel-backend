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
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
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
            // Default front-desk policy: no advance check-in and no
            // premature checkout (early departures need an amendment workflow).
            $hotelDate = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
            if ($operation === 'check_in'
                && ($hotelDate < $record->check_in || $hotelDate >= $record->check_out)) {
                throw new HttpException(409, 'Check-in is allowed from arrival day until departure day.');
            }
            if ($operation === 'check_out' && $hotelDate < $record->check_out) {
                throw new HttpException(409, 'Early check-out requires a separate stay amendment.');
            }

            if ($operation === 'check_in') {
                if ($record->assigned_room_id === null) {
                    throw new HttpException(409, 'Assign a physical room before check-in.');
                }

                if (app(ReadinessWriteGuard::class)->enabled($hotel)) {
                    app(ReadinessWriteGuard::class)->requireReady($hotel, (int) $record->assigned_room_id);
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
            if ($operation === 'check_out') {
                // Retire all mock keycard authorizations within this same
                // locked checkout transaction. No physical credential changes.
                app(\App\Domain\Keycards\KeycardAuthorizationService::class)
                    ->revokeForCompletedStay($hotel, (int) $bookingId, $actor);
                // Exactly one checkout turnover task per booking, written in the
                // same transaction as the state transition and its audit event.
                $taskId = DB::table('housekeeping_tasks')->insertGetId([
                    'hotel_id' => $hotel->id,
                    'room_id' => $record->assigned_room_id,
                    'type' => 'checkout_clean',
                    'status' => 'todo',
                    'assigned_to' => null,
                    'assigned_user_id' => null,
                    'due_at' => null,
                    'revision' => 1,
                    'created_by' => $actor,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('reservation_turnover_links')->insert([
                    'hotel_id' => $hotel->id,
                    'reservation_record_id' => $record->id,
                    'housekeeping_task_id' => $taskId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('housekeeping_task_events')->insert([
                    'hotel_id' => $hotel->id,
                    'task_id' => $taskId,
                    'actor_id' => $actor,
                    'action' => 'created',
                    'before_state' => null,
                    'after_state' => json_encode([
                        'status' => 'todo', 'assignedTo' => null,
                        'assignedUserId' => null, 'dueAt' => null,
                    ], JSON_THROW_ON_ERROR),
                    'note' => 'Checkout turnover for reservation '.$record->reference,
                    'created_at' => now(),
                ]);
            }

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

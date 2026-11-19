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
    public function transition(Hotel $hotel, int $actor, int $bookingId, string $operation, ?int $earlyApprovalId = null): array
    {
        if (! in_array($operation, ['check_in', 'check_out'], true)) {
            throw new \InvalidArgumentException('Unsupported reservation transition.');
        }

        return DB::transaction(function () use ($hotel, $actor, $bookingId, $operation, $earlyApprovalId): array {
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
            $early = $operation === 'check_in' && $hotelDate < $record->check_in;
            $earlyApproval = null;
            if ($early) {
                // Only the manager approval service can supply this ID.
                // Public check-in always calls transition without an approval.
                if ($earlyApprovalId === null
                    || \Carbon\CarbonImmutable::parse($record->check_in, $hotel->timezone ?: 'Asia/Manila')
                        ->subDay()->toDateString() !== $hotelDate) {
                    throw new HttpException(409, 'Early check-in requires a manager-approved request for the day before arrival.');
                }
                $earlyApproval = DB::table('manager_approval_requests')
                    ->where('hotel_id', $hotel->id)->where('reservation_record_id', $record->id)
                    ->where('id', $earlyApprovalId)->where('action', 'early_check_in')
                    ->where('status', 'pending')->lockForUpdate()->first();
                if ($earlyApproval === null || (int) $earlyApproval->requested_by === $actor
                    || now()->greaterThanOrEqualTo($earlyApproval->expires_at)) {
                    throw new HttpException(409, 'Early check-in manager approval is invalid or expired.');
                }
            }
            if ($operation === 'check_in' && $hotelDate >= $record->check_out) {
                throw new HttpException(409, 'The stay has already reached its departure date.');
            }
            $earlyDeparture = $operation === 'check_out' && $hotelDate < $record->check_out;
            if ($earlyDeparture) {
                // Normal checkout never bypasses stay dates; only the independent
                // manager-review transaction may authorize this specific exception.
                if ($earlyApprovalId === null || $hotelDate < $record->check_in) {
                    throw new HttpException(409, 'Early checkout requires manager approval after check-in.');
                }
                $earlyApproval = DB::table('manager_approval_requests')
                    ->where('hotel_id', $hotel->id)->where('reservation_record_id', $record->id)
                    ->where('id', $earlyApprovalId)->where('action', 'early_check_out')
                    ->where('status', 'pending')->lockForUpdate()->first();
                if ($earlyApproval === null || (int) $earlyApproval->requested_by === $actor
                    || now()->greaterThanOrEqualTo($earlyApproval->expires_at)) {
                    throw new HttpException(409, 'Early checkout approval is invalid or expired.');
                }
            }

            if ($operation === 'check_out'
                && app(SimplifiedCashCheckoutPolicy::class)->enabled()) {
                // Ordinary cash-only checkout is operational, not the final
                // independent cashier reconciliation or financial sign-off.
                app(SimplifiedCashCheckoutPolicy::class)->requireEligible($hotel, $bookingId);
                app(SimplifiedCashCheckoutPolicy::class)->recordCheckout($hotel, $bookingId, $actor);
            } elseif ($operation === 'check_out'
                && config('financial_accounting.enable_verified_settlement', false)) {
                $folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
                    ->where('reservation_record_id', $bookingId)->lockForUpdate()->first();
                if ($folio === null || !DB::table('folio_settlement_signoffs')
                    ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                    ->where('reservation_record_id', $bookingId)->exists()) {
                    throw new HttpException(409,
                        'Checkout requires an approved immutable folio settlement statement.');
                }
                $settlement = app(FolioSettlementService::class)->statement($hotel, $bookingId);
                if (!$settlement['signed'] || !$settlement['canSignOff']) {
                    throw new HttpException(409,
                        'Signed folio no longer reconciles; manager financial investigation required.');
                }
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
                    ->where('check_out', '>', $early ? $hotelDate : $record->check_in)->exists();
                if ($blocked) {
                    throw new HttpException(409, 'The assigned room has an active inventory block.');
                }

                $collision = DB::table('reservation_records')
                    ->where('hotel_id', $hotel->id)
                    ->where('assigned_room_id', $record->assigned_room_id)
                    ->where('id', '!=', $record->id)
                    ->where('check_in', '<', $record->check_out)
                    ->where('check_out', '>', $early ? $hotelDate : $record->check_in)
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

            // The actual occupied night must be reflected in operational
            // inventory, otherwise the previous night could be sold again.
            // Original booked dates remain immutable in the early-arrival audit.
            $changes = ['lifecycle_state' => $next, 'updated_at' => now()];
            if ($early) {
                $changes['check_in'] = $hotelDate;
            }
            DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $record->id)->update($changes);
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

            if ($early && $earlyApproval !== null) {
                DB::table('reservation_early_check_ins')->insert([
                    'hotel_id' => $hotel->id,
                    'reservation_record_id' => $record->id,
                    'approval_request_id' => $earlyApproval->id,
                    'requested_by' => $earlyApproval->requested_by,
                    'approved_by' => $actor, 'checked_in_by' => $actor,
                    'room_id' => $record->assigned_room_id,
                    'scheduled_check_in' => $record->check_in,
                    'scheduled_check_out' => $record->check_out,
                    'actual_check_in_date' => $hotelDate,
                    'actual_checked_in_at' => now(),
                    'reason' => $earlyApproval->reason,
                    'manager_note' => $earlyApproval->review_note ?: 'Approved by hotel manager.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('reservation_booking_events')->insert([
                'hotel_id' => $hotel->id,
                'reservation_record_id' => $record->id,
                'actor_id' => $actor,
                'event_type' => $early ? 'early_check_in' : ($earlyDeparture ? 'early_check_out' : $operation),
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
                'earlyDeparture' => $earlyDeparture,
            ];
        }, 3);
    }
}

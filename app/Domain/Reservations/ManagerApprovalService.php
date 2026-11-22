<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Narrow, audited manager approvals. An approval cannot bypass the existing
 * cancellation/amendment policy: the domain mutation and audit are one transaction.
 */
final class ManagerApprovalService
{
    public const ACTIONS = ['cancel_booking', 'amend_dates', 'early_check_in', 'early_check_out'];

    public function list(Hotel $hotel, int $actor, bool $manager, array $filters): array
    {
        $query = DB::table('manager_approval_requests')
            ->where('hotel_id', $hotel->id);
        if (! $manager) {
            $query->where('requested_by', $actor);
        }
        $asOf = now();
        $status = $filters['status'] ?? null;
        if ($status === 'pending') {
            $query->where('status', 'pending')->where('expires_at', '>', $asOf);
        } elseif ($status === 'expired') {
            $query->where('status', 'pending')->where('expires_at', '<=', $asOf);
        } elseif ($status === 'history') {
            $query->where(fn ($history) => $history->where('status', '!=', 'pending')
                ->orWhere('expires_at', '<=', $asOf));
        } elseif ($status !== null) {
            $query->where('status', $status);
        }
        if (isset($filters['bookingId'])) {
            $query->where('reservation_record_id', (int) $filters['bookingId']);
        }
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20));
        return [
            'data' => $page->getCollection()->map(fn (object $row): array => $this->present($row))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage()],
        ];
    }

    public function request(Hotel $hotel, int $actor, int $bookingId, array $input): array
    {
        $action = $input['action'];
        $reason = trim($input['reason']);
        $payload = $action === 'amend_dates'
            ? ['checkIn' => $input['checkIn'], 'checkOut' => $input['checkOut']]
            : [];
        if ($action === 'amend_dates') {
            // Check date syntax and bounds early; the mutation rechecks them later.
            $stay = new StayPeriod($payload['checkIn'], $payload['checkOut']);
            if ($stay->nights() > (int) config('booking.maximum_nights', 31)) {
                throw new HttpException(422, 'Requested stay exceeds the maximum nights.');
            }
        }

        $fingerprint = hash('sha256', json_encode([
            'requester' => $actor, 'bookingId' => $bookingId,
            'action' => $action, 'reason' => $reason, 'payload' => $payload,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($hotel, $actor, $bookingId, $action, $reason, $payload, $fingerprint, $input): array {
            // Shared hotel lock serializes request creation and manager decisions.
            app(ReadinessWriteGuard::class)->lockHotel($hotel);

            $existing = DB::table('manager_approval_requests')
                ->where('hotel_id', $hotel->id)->where('request_key', $input['idempotencyKey'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $fingerprint)) {
                    throw new HttpException(409, 'This request key was already used for different details.');
                }
                return $this->present($existing);
            }

            $booking = DB::table('reservation_records')
                ->where('hotel_id', $hotel->id)->where('id', $bookingId)->first();
            abort_unless($booking !== null, 404);
            $expectedState = $action === 'early_check_out' ? 'checked_in' : 'confirmed';
            if ($booking->lifecycle_state !== $expectedState) {
                throw new HttpException(409, 'This exception is not available for the current booking status.');
            }
            if (!in_array($action, ['early_check_in', 'early_check_out'], true)
                && DB::table('reservation_folios')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->exists()) {
                throw new HttpException(409, 'Priced reservations require a separate finance-approved workflow.');
            }
            if ($action === 'early_check_in') {
                $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
                $arrival = \Carbon\CarbonImmutable::parse($booking->check_in, $hotel->timezone ?: 'Asia/Manila');
                if ($arrival->subDay()->toDateString() !== $today) {
                    throw new HttpException(409, 'Early check-in requests are allowed only the day before scheduled arrival.');
                }
                if ($booking->assigned_room_id === null) {
                    throw new HttpException(409, 'Assign a physical room before requesting early check-in.');
                }
                if (DB::table('manager_approval_requests')->where('hotel_id', $hotel->id)
                    ->where('reservation_record_id', $bookingId)->where('action', 'early_check_in')
                    ->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
                    throw new HttpException(409, 'An early check-in request is already awaiting manager review.');
                }
            }
            if ($action === 'early_check_out') {
                $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
                if ($today < $booking->check_in || $today >= $booking->check_out) {
                    throw new HttpException(409, 'Early checkout requests are allowed only during a checked-in stay before scheduled departure.');
                }
                if (DB::table('manager_approval_requests')->where('hotel_id', $hotel->id)
                    ->where('reservation_record_id', $bookingId)->where('action', 'early_check_out')
                    ->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
                    throw new HttpException(409, 'An early checkout request is already awaiting manager review.');
                }
            }
            if ($action === 'amend_dates'
                && $payload['checkIn'] === $booking->check_in
                && $payload['checkOut'] === $booking->check_out) {
                throw new HttpException(422, 'Choose dates different from the recorded stay.');
            }
            if ($action === 'amend_dates' && now($hotel->timezone ?: 'Asia/Manila')->toDateString() >= $booking->check_in) {
                throw new HttpException(409, 'Date changes must be requested before planned arrival.');
            }

            $snapshot = [
                'checkIn' => $booking->check_in,
                'checkOut' => $booking->check_out,
                'status' => $booking->lifecycle_state,
                'roomTypeId' => (string) $booking->room_type_id,
                'assignedRoomId' => $booking->assigned_room_id === null ? null : (string) $booking->assigned_room_id,
            ];

            $id = DB::table('manager_approval_requests')->insertGetId([
                'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
                'requested_by' => $actor, 'request_key' => $input['idempotencyKey'],
                'payload_hash' => $fingerprint, 'action' => $action,
                'status' => 'pending', 'reason' => $reason,
                'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'booking_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'expires_at' => now()->addHours(48),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'requested', ['action' => $action, 'snapshot' => $snapshot]);
            return $this->present(DB::table('manager_approval_requests')->where('id', $id)->first());
        }, 3);
    }

    public function review(Hotel $hotel, int $actor, int $id, string $decision, ?string $note): array
    {
        return DB::transaction(function () use ($hotel, $actor, $id, $decision, $note): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $request = DB::table('manager_approval_requests')
                ->where('hotel_id', $hotel->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($request !== null, 404);
            if ($request->status !== 'pending') {
                throw new HttpException(409, 'This approval request has already been reviewed.');
            }
            if ($actor === (int) $request->requested_by) {
                throw new HttpException(403, 'You cannot approve or reject your own request.');
            }
            if (now()->greaterThanOrEqualTo($request->expires_at)) {
                throw new HttpException(409, 'This request has expired. Submit a new request.');
            }
            // A deactivated requester's old proposal cannot later be executed.
            $requesterActive = DB::table('hotel_memberships')
                ->join('users', 'users.id', '=', 'hotel_memberships.user_id')
                ->where('hotel_memberships.hotel_id', $hotel->id)
                ->where('hotel_memberships.user_id', $request->requested_by)
                ->where('hotel_memberships.is_active', true)
                ->whereIn('hotel_memberships.role', ['admin', 'front_desk'])
                ->where('users.is_active', true)->exists();
            if (! $requesterActive) {
                throw new HttpException(409, 'Requester no longer has an active authorized hotel role.');
            }

            $note = $note === null ? null : trim($note);
            if (in_array($request->action, ['early_check_in', 'early_check_out'], true)
                && $decision === 'approve' && mb_strlen($note ?? '') < 10) {
                throw new HttpException(422, 'Early check-in or checkout approval requires a manager note of at least 10 characters.');
            }
            if ($decision === 'reject') {
                DB::table('manager_approval_requests')->where('id', $id)->update([
                    'status' => 'rejected', 'reviewed_by' => $actor,
                    'review_note' => $note, 'reviewed_at' => now(), 'updated_at' => now(),
                ]);
                $this->event($hotel, $id, $actor, 'rejected', ['note' => $note]);
            } else {
                $booking = DB::table('reservation_records')
                    ->where('hotel_id', $hotel->id)
                    ->where('id', $request->reservation_record_id)->first();
                abort_unless($booking !== null, 404);
                $snapshot = json_decode($request->booking_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if ($booking->check_in !== $snapshot['checkIn']
                    || $booking->check_out !== $snapshot['checkOut']
                    || $booking->lifecycle_state !== $snapshot['status']
                    || (string) $booking->room_type_id !== $snapshot['roomTypeId']
                    || ($booking->assigned_room_id === null ? null : (string) $booking->assigned_room_id) !== $snapshot['assignedRoomId']) {
                    throw new HttpException(409, 'Booking changed after the request. Reopen the reservation and submit a new request.');
                }

                if ($request->action === 'early_check_out') {
                    $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
                    if ($booking->lifecycle_state !== 'checked_in' ||
                        $today < $booking->check_in || $today >= $booking->check_out) {
                        throw new HttpException(409, 'Early checkout is no longer eligible. Refresh the booking.');
                    }
                }
                if ($request->action === 'early_check_in'
                    && now($hotel->timezone ?: 'Asia/Manila')->toDateString() >= $booking->check_in) {
                    throw new HttpException(409, 'This is no longer an early arrival. Use the standard check-in flow.');
                }

                // The domain services preserve all inventory/folio checks and
                // audit actor attribution. Any failure rolls back approval too.
                $payload = json_decode($request->request_payload, true, 512, JSON_THROW_ON_ERROR);
                $result = match ($request->action) {
                    'cancel_booking' => app(ReservationBookingService::class)->cancel(
                        $hotel, $actor, (int) $request->reservation_record_id
                    ),
                    'amend_dates' => app(ReservationAmendmentService::class)->amend(
                        $hotel, $actor, (int) $request->reservation_record_id, $payload
                    ),
                    'early_check_in' => app(ReservationStayLifecycleService::class)->transition(
                        $hotel, $actor, (int) $request->reservation_record_id, 'check_in', $id
                    ),
                    'early_check_out' => app(ReservationStayLifecycleService::class)->transition(
                        $hotel, $actor, (int) $request->reservation_record_id, 'check_out', $id
                    ),
                    default => throw new HttpException(409, 'Unsupported approval action.'),
                };

                if ($request->action === 'early_check_in') {
                    DB::table('reservation_early_check_ins')->where('hotel_id', $hotel->id)
                        ->where('approval_request_id', $id)->update([
                            'manager_note' => $note ?: 'Approved by hotel manager.',
                            'updated_at' => now(),
                        ]);
                }

                DB::table('manager_approval_requests')->where('id', $id)->update([
                    'status' => 'executed', 'reviewed_by' => $actor,
                    'executed_by' => $actor, 'review_note' => $note,
                    'reviewed_at' => now(), 'executed_at' => now(), 'updated_at' => now(),
                ]);
                $this->event($hotel, $id, $actor, 'approved', ['note' => $note]);
                $this->event($hotel, $id, $actor, 'executed', ['result' => $result]);
            }

            return $this->present(DB::table('manager_approval_requests')->where('id', $id)->first());
        }, 3);
    }

    public function history(Hotel $hotel, int $actor, bool $manager, int $id): array
    {
        $request = DB::table('manager_approval_requests')
            ->where('hotel_id', $hotel->id)->where('id', $id)->first();
        abort_unless($request !== null, 404);
        if (! $manager && (int) $request->requested_by !== $actor) {
            abort(404);
        }
        return DB::table('manager_approval_events')
            ->leftJoin('users as audit_actor','audit_actor.id','=','manager_approval_events.actor_id')
            ->where('manager_approval_events.hotel_id', $hotel->id)->where('manager_approval_events.approval_request_id', $id)
            ->orderBy('manager_approval_events.id')->get(['manager_approval_events.*','audit_actor.name as actor_name'])->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'event' => $row->event,
                'actorId' => (string) $row->actor_id,
                'actorName' => $row->actor_name,
                'details' => $row->details ? json_decode($row->details, true) : null,
                'createdAt' => $row->created_at,
            ])->all();
    }

    private function event(Hotel $hotel, int $requestId, int $actor, string $event, array $details): void
    {
        DB::table('manager_approval_events')->insert([
            'hotel_id' => $hotel->id, 'approval_request_id' => $requestId,
            'actor_id' => $actor, 'event' => $event,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'hotelId' => (string) $row->hotel_id,
            'bookingId' => (string) $row->reservation_record_id,
            'requestedBy' => (string) $row->requested_by,
            'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
            'executedBy' => $row->executed_by === null ? null : (string) $row->executed_by,
            'action' => $row->action,
            'status' => $row->status === 'pending' && now()->greaterThanOrEqualTo($row->expires_at)
                ? 'expired' : $row->status,
            'reason' => $row->reason,
            'payload' => $row->action === 'amend_dates'
                ? json_decode($row->request_payload, true, 512, JSON_THROW_ON_ERROR)
                : (object) [],
            'snapshot' => json_decode($row->booking_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'reviewNote' => $row->review_note,
            'expiresAt' => $row->expires_at,
            'createdAt' => $row->created_at,
            'reviewedAt' => $row->reviewed_at,
            'executedAt' => $row->executed_at,
        ];
    }
}

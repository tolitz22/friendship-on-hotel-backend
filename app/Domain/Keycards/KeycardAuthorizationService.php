<?php

declare(strict_types=1);

namespace App\Domain\Keycards;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class KeycardAuthorizationService
{
    private function lock(Hotel $hotel): void
    {
        app(ReadinessWriteGuard::class)->lockHotel($hotel);
    }

    private function event(Hotel $hotel, int $id, int $actor, string $event, array $details): void
    {
        DB::table('keycard_authorization_events')->insert([
            'hotel_id' => $hotel->id, 'authorization_id' => $id,
            'actor_id' => $actor, 'event' => $event,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    private function entry(Hotel $hotel, int $id, bool $lock = false): object
    {
        $query = DB::table('keycard_authorizations')->where('hotel_id', $hotel->id)->where('id', $id);
        $row = $lock ? $query->lockForUpdate()->first() : $query->first();
        abort_unless($row !== null, 404);
        return $row;
    }

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'hotelId' => (string) $row->hotel_id,
            'bookingId' => $row->reservation_record_id === null ? null : (string) $row->reservation_record_id,
            'roomId' => (string) $row->room_id,
            'kind' => $row->kind,
            'status' => $row->status,
            'provider' => $row->provider,
            'providerReference' => $row->provider_reference,
            'physicalCardEncoded' => false,
            'requestedBy' => (string) $row->requested_by,
            'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
            'revokedBy' => $row->revoked_by === null ? null : (string) $row->revoked_by,
            'reason' => $row->request_reason,
            'reviewNote' => $row->review_note,
            'revocationNote' => $row->revocation_note,
            'validFrom' => $row->valid_from,
            'validUntil' => $row->valid_until,
            'createdAt' => $row->created_at,
            'reviewedAt' => $row->reviewed_at,
            'revokedAt' => $row->revoked_at,
        ];
    }

    private function verifyBooking(Hotel $hotel, int $bookingId, ?int $expectedRoom = null): object
    {
        $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('id', $bookingId)->first();
        abort_unless($booking !== null, 404);
        if ($booking->lifecycle_state !== 'checked_in' || $booking->assigned_room_id === null
            || ($expectedRoom !== null && (int) $booking->assigned_room_id !== $expectedRoom)) {
            throw new HttpException(409, 'Keycard authorization requires a checked-in reservation and its currently assigned room.');
        }
        $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        if ($today < $booking->check_in || $today >= $booking->check_out) {
            throw new HttpException(409, 'The registered stay is not within its valid access dates.');
        }
        return $booking;
    }

    private function issueMock(Hotel $hotel, int $id, int $actor): void
    {
        // Physically encoding cards is deliberately unsupported. The binding
        // is a code-level adapter; it must never emit real lock credentials.
        $provider = app(KeycardProvider::class)->authorize((int) $hotel->id, $id);
        if ($provider['physicalCardEncoded'] !== false || $provider['provider'] !== 'mock') {
            throw new HttpException(503, 'Physical keycard issuance is not configured.');
        }
        DB::table('keycard_authorizations')->where('id', $id)->update([
            'status' => 'authorized_mock', 'provider' => 'mock',
            'provider_reference' => $provider['reference'], 'updated_at' => now(),
        ]);
        $this->event($hotel, $id, $actor, 'authorized_mock', [
            'reference' => $provider['reference'], 'physicalCardEncoded' => false,
        ]);
    }

    public function listing(Hotel $hotel, int $actor, bool $manager, array $filters): array
    {
        $query = DB::table('keycard_authorizations')->where('hotel_id', $hotel->id);
        if (! $manager) {
            $query->where('requested_by', $actor);
        }
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        if (isset($filters['bookingId'])) $query->where('reservation_record_id', $filters['bookingId']);
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20));
        return ['data' => $page->getCollection()->map(fn (object $row): array => $this->present($row))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage()]];
    }

    public function history(Hotel $hotel, int $actor, bool $manager, int $id): array
    {
        $row = $this->entry($hotel, $id);
        abort_unless($manager || (int) $row->requested_by === $actor, 404);
        return DB::table('keycard_authorization_events')
            ->where('hotel_id', $hotel->id)->where('authorization_id', $id)
            ->orderBy('id')->get()->map(fn (object $event): array => [
                'id' => (string) $event->id, 'actorId' => (string) $event->actor_id,
                'event' => $event->event, 'details' => json_decode($event->details, true),
                'createdAt' => $event->created_at,
            ])->all();
    }

    public function request(Hotel $hotel, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $input): array {
            $this->lock($hotel);
            $kind = $input['kind'];
            $bookingId = isset($input['bookingId']) ? (int) $input['bookingId'] : null;
            $roomId = isset($input['roomId']) ? (int) $input['roomId'] : null;
            if ($kind === 'emergency_access') {
                if ($bookingId !== null || $roomId === null) {
                    throw new HttpException(422, 'Emergency-access request requires a room, without a guest booking.');
                }
                abort_unless(DB::table('rooms')->where('hotel_id', $hotel->id)
                    ->where('id', $roomId)->exists(), 404);
                $date = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
                $until = now($hotel->timezone ?: 'Asia/Manila')->addDay()->toDateString();
            } else {
                if ($bookingId === null || $roomId !== null) {
                    throw new HttpException(422, 'Guest keycard requests require a booking, not a manually selected room.');
                }
                $booking = $this->verifyBooking($hotel, $bookingId);
                $roomId = (int) $booking->assigned_room_id;
                $date = $booking->check_in;
                $until = $booking->check_out;
                if ($kind === 'replacement') {
                    $previous = DB::table('keycard_authorizations')->where('hotel_id', $hotel->id)
                        ->where('reservation_record_id', $bookingId)
                        ->where('room_id', $roomId)->where('kind', 'initial')
                        ->where('status', 'authorized_mock')->exists();
                    if (! $previous) {
                        throw new HttpException(409, 'Replacement requires an existing, non-revoked initial authorization.');
                    }
                }
            }

            $reason = trim($input['reason']);
            $fingerprint = hash('sha256', json_encode([
                'kind' => $kind, 'bookingId' => $bookingId, 'roomId' => $roomId,
                'reason' => $reason, 'requestedBy' => $actor,
            ], JSON_THROW_ON_ERROR));
            $existing = DB::table('keycard_authorizations')
                ->where('hotel_id', $hotel->id)->where('idempotency_key', $input['idempotencyKey'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $fingerprint)) {
                    throw new HttpException(409, 'Idempotency key was already used for another keycard request.');
                }
                return $this->present($existing);
            }
            if ($kind === 'initial' && DB::table('keycard_authorizations')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->where('room_id', $roomId)
                ->where('kind', 'initial')->where('status', 'authorized_mock')->exists()) {
                throw new HttpException(409, 'An active initial keycard authorization already exists.');
            }
            if ($kind !== 'initial') {
                $pending = DB::table('keycard_authorizations')->where('hotel_id', $hotel->id)
                    ->where('kind', $kind)->where('room_id', $roomId)
                    ->where('status', 'pending_manager');
                if ($bookingId === null) $pending->whereNull('reservation_record_id');
                else $pending->where('reservation_record_id', $bookingId);
                if ($pending->exists()) {
                    throw new HttpException(409, 'An equivalent manager-review request is already pending.');
                }
            }
            $id = DB::table('keycard_authorizations')->insertGetId([
                'hotel_id' => $hotel->id, 'room_id' => $roomId,
                'reservation_record_id' => $bookingId, 'requested_by' => $actor,
                'idempotency_key' => $input['idempotencyKey'],
                'request_hash' => $fingerprint, 'kind' => $kind,
                'status' => $kind === 'initial' ? 'requested' : 'pending_manager',
                'provider' => 'mock', 'request_reason' => $reason,
                'valid_from' => $date, 'valid_until' => $until,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'requested', [
                'kind' => $kind, 'roomId' => (string) $roomId,
                'bookingId' => $bookingId === null ? null : (string) $bookingId,
                'reason' => $reason,
            ]);
            if ($kind === 'initial') {
                $this->issueMock($hotel, $id, $actor);
            }
            return $this->present($this->entry($hotel, $id));
        }, 3);
    }

    public function review(Hotel $hotel, int $actor, int $id, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $id, $input): array {
            $this->lock($hotel);
            $row = $this->entry($hotel, $id, true);
            if ($row->status !== 'pending_manager') {
                throw new HttpException(409, 'This keycard exception was already handled.');
            }
            if ((int) $row->requested_by === $actor) abort(403);
            // Rejection is always possible for a pending request. Only
            // approval must re-qualify the requester and current room/stay.
            if ($input['decision'] === 'approve') {
                $activeRequester = DB::table('hotel_memberships as m')
                    ->join('users as u', 'u.id', '=', 'm.user_id')
                    ->where('m.hotel_id', $hotel->id)
                    ->where('m.user_id', $row->requested_by)
                    ->where('m.is_active', true)->where('u.is_active', true)
                    ->whereIn('m.role', ['front_desk', 'admin'])->exists();
                if (! $activeRequester) {
                    throw new HttpException(409, 'Requester no longer has an active authorized hotel role.');
                }
                if ($row->kind === 'emergency_access') {
                    if ($row->valid_from !== now($hotel->timezone ?: 'Asia/Manila')->toDateString()) {
                        throw new HttpException(409, 'Emergency request has expired.');
                    }
                    abort_unless(DB::table('rooms')->where('hotel_id', $hotel->id)
                        ->where('id', $row->room_id)->exists(), 404);
                } else {
                    $booking = $this->verifyBooking($hotel, (int) $row->reservation_record_id, (int) $row->room_id);
                    if ($booking->check_in !== $row->valid_from || $booking->check_out !== $row->valid_until) {
                        throw new HttpException(409, 'Booking dates changed after the request.');
                    }
                    if ($row->kind === 'replacement' && ! DB::table('keycard_authorizations')
                        ->where('hotel_id', $hotel->id)->where('reservation_record_id', $row->reservation_record_id)
                        ->where('room_id', $row->room_id)->where('kind', 'initial')
                        ->where('status', 'authorized_mock')->exists()) {
                        throw new HttpException(409, 'The original authorization was revoked. Replacement request is no longer eligible.');
                    }
                }
            }
            $status = $input['decision'] === 'approve' ? 'approved' : 'rejected';
            DB::table('keycard_authorizations')->where('id', $id)->update([
                'status' => $status, 'reviewed_by' => $actor,
                'review_note' => trim($input['note']), 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, $status, [
                'reviewNote' => trim($input['note']), 'physicalCardEncoded' => false,
            ]);
            if ($status === 'approved') $this->issueMock($hotel, $id, $actor);
            return $this->present($this->entry($hotel, $id));
        }, 3);
    }

    public function revoke(Hotel $hotel, int $actor, int $id, string $note): array
    {
        return DB::transaction(function () use ($hotel, $actor, $id, $note): array {
            $this->lock($hotel);
            $row = $this->entry($hotel, $id, true);
            if ($row->status !== 'authorized_mock') {
                throw new HttpException(409, 'Only an active mock authorization can be revoked.');
            }
            DB::table('keycard_authorizations')->where('id', $id)->update([
                'status' => 'revoked', 'revoked_by' => $actor,
                'revocation_note' => trim($note),
                'revoked_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'revoked', [
                'note' => trim($note), 'physicalCardRevoked' => false,
            ]);
            return $this->present($this->entry($hotel, $id));
        }, 3);
    }
}

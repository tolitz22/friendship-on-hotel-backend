<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OccupancyVerificationService
{
    private function event(Hotel $hotel, int $id, int $actor, string $event, array $details): void
    {
        DB::table('occupancy_verification_events')->insert([
            'hotel_id' => $hotel->id, 'verification_id' => $id, 'actor_id' => $actor,
            'event' => $event, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function record(Hotel $hotel, int $id, bool $lock = false): object
    {
        $query = DB::table('occupancy_verifications as v')
            ->join('rooms as r', function ($join): void {
                $join->on('r.id', '=', 'v.room_id')->on('r.hotel_id', '=', 'v.hotel_id');
            })
            ->where('v.hotel_id', $hotel->id)->where('v.id', $id)
            ->select('v.*', 'r.number as room_number');
        $record = $lock ? $query->lockForUpdate()->first() : $query->first();
        abort_unless($record !== null, 404);
        return $record;
    }

    private function visible(object $row, int $actor, bool $manager): bool
    {
        return $manager || (int) $row->assigned_user_id === $actor;
    }

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id, 'hotelId' => (string) $row->hotel_id,
            'roomId' => (string) $row->room_id, 'roomNumber' => $row->room_number,
            'inspectionDate' => $row->inspection_date,
            'assignedUserId' => (string) $row->assigned_user_id,
            'createdBy' => (string) $row->created_by,
            'observedBy' => $row->observed_by === null ? null : (string) $row->observed_by,
            'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
            'status' => $row->status, 'observedOccupancy' => $row->observed_occupancy,
            'expectedOccupancy' => $row->expected_occupancy, 'discrepancy' => $row->discrepancy,
            'expectedBookingIds' => $row->expected_booking_ids
                ? json_decode($row->expected_booking_ids, true, 512, JSON_THROW_ON_ERROR) : null,
            'observationNote' => $row->observation_note,
            'reviewDecision' => $row->review_decision, 'reviewNote' => $row->review_note,
            'observedAt' => $row->observed_at, 'reviewedAt' => $row->reviewed_at,
            'createdAt' => $row->created_at,
        ];
    }

    public function listing(Hotel $hotel, int $actor, bool $manager, array $input): array
    {
        $query = DB::table('occupancy_verifications as v')
            ->join('rooms as r', function ($join): void {
                $join->on('r.id', '=', 'v.room_id')->on('r.hotel_id', '=', 'v.hotel_id');
            })->where('v.hotel_id', $hotel->id)->select('v.*', 'r.number as room_number');
        if (! $manager) $query->where('v.assigned_user_id', $actor);
        if (isset($input['status'])) $query->where('v.status', $input['status']);
        if (isset($input['date'])) $query->where('v.inspection_date', $input['date']);
        $page = $query->orderByDesc('v.id')->paginate((int) ($input['per_page'] ?? 20));
        return [
            'data' => $page->getCollection()->map(fn (object $row) => $this->present($row))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage(),
                'hotelDate' => now($hotel->timezone ?: 'Asia/Manila')->toDateString()],
        ];
    }

    public function details(Hotel $hotel, int $actor, bool $manager, int $id): array
    {
        $row = $this->record($hotel, $id);
        abort_unless($this->visible($row, $actor, $manager), 404);
        return $this->present($row);
    }

    public function history(Hotel $hotel, int $actor, bool $manager, int $id): array
    {
        $this->details($hotel, $actor, $manager, $id);
        return DB::table('occupancy_verification_events')
            ->where('hotel_id', $hotel->id)->where('verification_id', $id)
            ->orderBy('id')->get()->map(fn (object $row): array => [
                'id' => (string) $row->id, 'actorId' => (string) $row->actor_id,
                'event' => $row->event, 'details' => json_decode($row->details, true),
                'createdAt' => $row->created_at,
            ])->all();
    }

    public function assign(Hotel $hotel, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            abort_unless(DB::table('rooms')->where('hotel_id', $hotel->id)
                ->where('id', $input['roomId'])->exists(), 404);
            $verifier = DB::table('hotel_memberships as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.hotel_id', $hotel->id)->where('m.user_id', $input['assignedUserId'])
                ->where('m.is_active', true)->where('u.is_active', true)
                ->where('m.role', 'housekeeping')->exists();
            if (! $verifier) {
                throw new HttpException(422, 'Verifier must be an active housekeeping employee of this hotel.');
            }
            if ($input['inspectionDate'] !== now($hotel->timezone ?: 'Asia/Manila')->toDateString()) {
                throw new HttpException(422, 'Only current hotel-local date can be assigned.');
            }
            if (DB::table('occupancy_verifications')->where('hotel_id', $hotel->id)
                ->where('room_id', $input['roomId'])
                ->where('inspection_date', $input['inspectionDate'])->exists()) {
                throw new HttpException(409, 'This room already has an occupancy verification for the date.');
            }
            $id = DB::table('occupancy_verifications')->insertGetId([
                'hotel_id' => $hotel->id, 'room_id' => $input['roomId'],
                'inspection_date' => $input['inspectionDate'],
                'assigned_user_id' => $input['assignedUserId'],
                'created_by' => $actor, 'status' => 'assigned',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'assigned', [
                'roomId' => (string) $input['roomId'], 'assignedUserId' => (string) $input['assignedUserId'],
                'inspectionDate' => $input['inspectionDate'],
            ]);
            return $this->present($this->record($hotel, $id));
        }, 3);
    }

    public function observe(Hotel $hotel, int $actor, int $id, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $id, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $row = $this->record($hotel, $id, true);
            if ((int) $row->assigned_user_id !== $actor) abort(403);
            if ($row->status !== 'assigned') {
                throw new HttpException(409, 'Observation already submitted. Ask a manager to review the recorded evidence.');
            }
            $isHousekeeping = DB::table('hotel_memberships')
                ->where('hotel_id', $hotel->id)->where('user_id', $actor)
                ->where('role', 'housekeeping')->where('is_active', true)->exists();
            if (! $isHousekeeping) abort(403);
            if ($row->inspection_date !== now($hotel->timezone ?: 'Asia/Manila')->toDateString()) {
                throw new HttpException(409, 'This verification is no longer current. Request a new daily assignment.');
            }

            // Hotel-first lock makes this a consistent snapshot with all PMS
            // booking/check-in/assignment writes using the same protocol.
            $bookings = DB::table('reservation_records')
                ->where('hotel_id', $hotel->id)->where('assigned_room_id', $row->room_id)
                ->where('lifecycle_state', 'checked_in')
                ->where('check_in', '<=', $row->inspection_date)
                ->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $expected = count($bookings) ? 'occupied' : 'vacant';
            $observed = $input['observedOccupancy'];
            $discrepancy = $observed === 'unknown' ? 'unverified'
                : ($observed === $expected ? 'match'
                : ($observed === 'occupied' ? 'unexpected_occupied' : 'expected_occupied_vacant'));

            DB::table('occupancy_verifications')->where('id', $id)->update([
                'status' => 'submitted', 'observed_occupancy' => $observed,
                'expected_occupancy' => $expected, 'discrepancy' => $discrepancy,
                'expected_booking_ids' => json_encode($bookings, JSON_THROW_ON_ERROR),
                'observed_by' => $actor, 'observation_note' => trim($input['note']),
                'observed_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'observed', [
                'observedOccupancy' => $observed, 'expectedOccupancy' => $expected,
                'expectedBookingIds' => $bookings, 'discrepancy' => $discrepancy,
                'note' => trim($input['note']),
            ]);
            return $this->present($this->record($hotel, $id));
        }, 3);
    }

    public function review(Hotel $hotel, int $actor, int $id, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $id, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $row = $this->record($hotel, $id, true);
            if ($row->status !== 'submitted') {
                throw new HttpException(409, 'Only submitted observations may be reviewed.');
            }
            if ((int) $row->observed_by === $actor) abort(403);
            $decision = $input['decision'];
            if ($decision === 'verified' && $row->discrepancy !== 'match') {
                throw new HttpException(409, 'Discrepant or unknown observations require investigation, not automatic verification.');
            }
            DB::table('occupancy_verifications')->where('id', $id)->update([
                'status' => $decision === 'verified' ? 'verified' : 'flagged',
                'reviewed_by' => $actor, 'review_decision' => $decision,
                'review_note' => trim($input['note']), 'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'reviewed', [
                'decision' => $decision, 'note' => trim($input['note']),
                'discrepancy' => $row->discrepancy,
            ]);
            return $this->present($this->record($hotel, $id));
        }, 3);
    }
}

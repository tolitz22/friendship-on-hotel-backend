<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RoomReadinessService
{
    private function room(Hotel $hotel, int $roomId, bool $lock = false): object
    {
        $query = DB::table('rooms')->where('hotel_id', $hotel->id)->where('id', $roomId);
        if ($lock) $query->lockForUpdate();
        $room = $query->first();
        abort_unless($room !== null, 404);
        return $room;
    }

    /**
     * Conservative, historical-independent checks. A successful inspection
     * is NEVER an inventory allocation, occupancy status or final eligibility.
     */
    public function blockers(Hotel $hotel, int $roomId): array
    {
        $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $tomorrow = now($hotel->timezone ?: 'Asia/Manila')->addDay()->toDateString();

        $blocks = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)
            ->where('room_id', $roomId)->where('is_active', true)
            ->where('check_in', '<', $tomorrow)->where('check_out', '>', $today)->exists();

        $maintenance = DB::table('maintenance_work_orders')->where('hotel_id', $hotel->id)
            ->where('room_id', $roomId)->where('affects_room', true)
            ->whereIn('status', ['open', 'in_progress'])->exists();

        $turnover = DB::table('reservation_turnover_links as links')
            ->join('housekeeping_tasks as tasks', 'tasks.id', '=', 'links.housekeeping_task_id')
            ->where('links.hotel_id', $hotel->id)
            ->where('tasks.hotel_id', $hotel->id)
            ->where('tasks.room_id', $roomId)
            ->orderByDesc('links.id')
            ->first(['tasks.id', 'tasks.status', 'links.created_at']);
        $unfinishedTurnover = $turnover !== null && $turnover->status !== 'done';
        $lastInspection = $this->latest($hotel, $roomId);
        $newTurnoverSinceInspection = $turnover !== null && $lastInspection !== null
            && $turnover->created_at > $lastInspection->created_at;

        return [
            'activeInventoryBlockToday' => $blocks,
            'activeRoomImpactMaintenance' => $maintenance,
            'latestTurnoverIncomplete' => $unfinishedTurnover,
            'newTurnoverSinceInspection' => $newTurnoverSinceInspection,
        ];
    }

    private function latest(Hotel $hotel, int $roomId): ?object
    {
        return DB::table('room_readiness_inspections')
            ->where('hotel_id', $hotel->id)->where('room_id', $roomId)
            ->orderByDesc('id')->first();
    }

    private function present(?object $inspection): ?array
    {
        if ($inspection === null) return null;
        return [
            'id' => (string) $inspection->id,
            'roomId' => (string) $inspection->room_id,
            'revision' => (int) $inspection->revision,
            'status' => $inspection->status,
            'checks' => [
                'cleaningVerified' => (bool) $inspection->cleaning_verified,
                'safetyVerified' => (bool) $inspection->safety_verified,
                'amenitiesVerified' => (bool) $inspection->amenities_verified,
            ],
            'inspectorNote' => $inspection->inspector_note,
            'inspectedBy' => (string) $inspection->inspected_by,
            'reviewedBy' => $inspection->reviewed_by === null ? null : (string) $inspection->reviewed_by,
            'reviewNote' => $inspection->review_note,
            'createdAt' => $inspection->created_at,
        ];
    }

    public function show(Hotel $hotel, int $roomId): array
    {
        $this->room($hotel, $roomId);
        $blockers = $this->blockers($hotel, $roomId);
        $latest = $this->latest($hotel, $roomId);
        return [
            'hotelId' => (string) $hotel->id,
            'roomId' => (string) $roomId,
            'inspection' => $this->present($latest),
            'blockers' => $blockers,
            'advisoryReady' => $latest !== null && $latest->status === 'approved'
                && ! in_array(true, array_values($blockers), true),
            'enforcedAtCheckIn' => app(ReadinessWriteGuard::class)->enabled($hotel),
            'inventoryGuaranteed' => false,
        ];
    }

    private function event(Hotel $hotel, int $roomId, int $inspectionId, int $actor, string $action, array $details): void
    {
        DB::table('room_readiness_events')->insert([
            'hotel_id' => $hotel->id, 'room_id' => $roomId,
            'inspection_id' => $inspectionId, 'actor_id' => $actor,
            'action' => $action, 'details_json' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public function inspect(Hotel $hotel, int $roomId, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $roomId, $actor, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $room = $this->room($hotel, $roomId, true);
            $previous = $this->latest($hotel, $roomId);
            if ($previous !== null && $previous->status === 'submitted') {
                throw new HttpException(409, 'Resolve the previous submitted inspection before creating another.');
            }

            $id = DB::table('room_readiness_inspections')->insertGetId([
                'hotel_id' => $hotel->id, 'room_id' => $room->id,
                'revision' => 1, 'status' => 'submitted',
                'cleaning_verified' => $input['cleaningVerified'],
                'safety_verified' => $input['safetyVerified'],
                'amenities_verified' => $input['amenitiesVerified'],
                'inspector_note' => trim($input['note']),
                'inspected_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $roomId, $id, $actor, 'submitted', [
                'checks' => [
                    'cleaningVerified' => $input['cleaningVerified'],
                    'safetyVerified' => $input['safetyVerified'],
                    'amenitiesVerified' => $input['amenitiesVerified'],
                ],
            ]);
            return $this->show($hotel, $roomId);
        }, 3);
    }

    public function review(Hotel $hotel, int $roomId, int $inspectionId, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $roomId, $inspectionId, $actor, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->room($hotel, $roomId, true);
            $latest = $this->latest($hotel, $roomId);
            abort_unless($latest !== null && (int) $latest->id === $inspectionId, 404);
            if ($latest->status !== 'submitted') throw new HttpException(409, 'Inspection was already reviewed.');
            if ((int) $latest->revision !== $input['expectedRevision']) {
                throw new HttpException(409, 'Inspection revision is stale.');
            }
            if ((int) $latest->inspected_by === $actor && $input['decision'] === 'approve') {
                throw new HttpException(403, 'Inspector cannot approve their own inspection.');
            }
            if ($input['decision'] === 'approve') {
                if (! $latest->cleaning_verified || ! $latest->safety_verified || ! $latest->amenities_verified) {
                    throw new HttpException(409, 'All inspection checklist items must pass before approval.');
                }
                if (in_array(true, array_values($this->blockers($hotel, $roomId)), true)) {
                    throw new HttpException(409, 'Inspection cannot be approved while operational blockers exist.');
                }
            }
            $status = $input['decision'] === 'approve' ? 'approved' : 'rejected';
            DB::table('room_readiness_inspections')->where('id', $latest->id)->update([
                'status' => $status, 'revision' => $latest->revision + 1,
                'reviewed_by' => $actor, 'review_note' => trim($input['note']),
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $roomId, $inspectionId, $actor, $status, [
                'note' => trim($input['note']),
            ]);
            return $this->show($hotel, $roomId);
        }, 3);
    }

    public function history(Hotel $hotel, int $roomId, int $page): array
    {
        $this->room($hotel, $roomId);
        $result = DB::table('room_readiness_events')
            ->where('hotel_id', $hotel->id)->where('room_id', $roomId)
            ->orderByDesc('id')->paginate(20, ['*'], 'page', $page);
        return [
            'data' => $result->getCollection()->map(fn (object $event): array => [
                'id' => (string) $event->id,
                'inspectionId' => (string) $event->inspection_id,
                'actorId' => (string) $event->actor_id,
                'action' => $event->action,
                'details' => json_decode($event->details_json, true, flags: JSON_THROW_ON_ERROR),
                'createdAt' => $event->created_at,
            ])->values(),
            'meta' => [
                'page' => $result->currentPage(), 'perPage' => $result->perPage(),
                'total' => $result->total(), 'totalPages' => $result->lastPage(),
            ],
        ];
    }
}

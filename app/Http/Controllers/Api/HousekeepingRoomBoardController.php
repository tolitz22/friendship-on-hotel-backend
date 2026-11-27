<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomInventoryStatusService;
use App\Domain\Reservations\RoomReadinessDashboardService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A room-only operational view. Never serialize reservation or guest records. */
final class HousekeepingRoomBoardController
{
    public function __invoke(Request $request, Hotel $hotel, RoomInventoryStatusService $inventory): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->is_active && $user->memberships()->where('hotel_id', $hotel->id)
            ->where('is_active', true)->where('role', 'housekeeping')->exists(), 403);

        $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $rooms = DB::table('rooms as r')->join('room_types as rt', function ($join): void {
            $join->on('rt.id', '=', 'r.room_type_id')->on('rt.hotel_id', '=', 'r.hotel_id');
        })->where('r.hotel_id', $hotel->id)->orderBy('r.id')
            ->limit(RoomReadinessDashboardService::MAX_ROOMS + 1)
            ->get(['r.id', 'r.number', 'r.floor_number', 'rt.name as room_type']);
        abort_if($rooms->count() > RoomReadinessDashboardService::MAX_ROOMS, 422,
            'Room board exceeds the supported complete snapshot size.');
        $ids = $rooms->pluck('id')->all();
        $statuses = collect();
        foreach (array_chunk($ids, 100) as $chunk) {
            $statuses = $statuses->concat($inventory->forRooms($hotel, $chunk)['data']);
        }
        $statuses = $statuses->keyBy('roomId');
        $arrivals = DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('lifecycle_state', 'confirmed')->where('check_in', $today)
            ->whereIn('assigned_room_id', $ids)->distinct()->pluck('assigned_room_id')->flip();
        $activeTasks = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $ids)->whereIn('status', ['todo', 'in_progress'])
            ->orderByRaw("CASE WHEN type = 'checkout_clean' THEN 0 WHEN type = 'inspection' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderByDesc('id')->get(['id', 'room_id', 'type', 'status', 'assigned_user_id'])
            ->groupBy('room_id');
        $latestCheckoutIds = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $ids)->where('type', 'checkout_clean')
            ->selectRaw('MAX(id) as id')->groupBy('room_id')->pluck('id');
        $checkoutTasks = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
            ->whereIn('id', $latestCheckoutIds)->get(['id', 'room_id', 'type', 'status', 'assigned_user_id'])
            ->keyBy('room_id');
        $rows = [];
        foreach ($rooms as $room) {
            $id = (string) $room->id;
            $status = $statuses->get($id);
            abort_if($status === null, 409, 'Room status changed during the snapshot. Refresh the board.');
            $tasks = $activeTasks->get($room->id, collect());
            $checkout = $checkoutTasks->get($room->id);
            $roomCleaning = $tasks->first(fn ($task) => $task->type !== 'stayover');
            $inProgress = $tasks->contains('status', 'in_progress');
            $blocked = $status['maintenanceBlocked'] || $status['inventoryBlockedToday'];
            $stage = match (true) {
                $blocked => 'blocked',
                $inProgress => 'cleaning',
                $roomCleaning !== null || $status['cleaning'] === 'checkout_cleaning' => 'dirty',
                $tasks->isNotEmpty() => 'service_due',
                $status['occupancy'] !== 'vacant' => 'occupied',
                $status['advisoryReady'] => 'ready',
                $checkout?->status === 'done' || $status['cleaning'] === 'awaiting_inspection' => 'inspection_due',
                default => 'unverified',
            };
            $task = $tasks->first(fn ($task) => (string) $task->assigned_user_id === (string) $user->id)
                ?? $tasks->first();
            if ($task === null && $stage === 'inspection_due' && $checkout?->status === 'done') $task = $checkout;
            $rows[] = [
                'roomId' => $id, 'roomNumber' => $room->number, 'roomType' => $room->room_type,
                'floor' => $room->floor_number === null ? null : (int) $room->floor_number,
                'occupancy' => $status['occupancy'], 'stage' => $stage,
                'arrivalToday' => $arrivals->has($room->id),
                'maintenanceBlocked' => $status['maintenanceBlocked'],
                'inventoryBlocked' => $status['inventoryBlockedToday'],
                'task' => $task === null ? null : [
                    'id' => (string) $task->id, 'type' => $task->type, 'status' => $task->status,
                    'assignment' => $task->assigned_user_id === null ? 'unassigned'
                        : ((string) $task->assigned_user_id === (string) $user->id ? 'mine' : 'other'),
                ],
            ];
        }

        return response()->json(['data' => [
            'hotelId' => (string) $hotel->id, 'userId' => (string) $user->id, 'hotelDate' => $today,
            'rooms' => $rows,
        ], 'meta' => ['total' => count($rows), 'snapshotOnly' => true, 'physicalOccupancyVerified' => false]]);
    }
}

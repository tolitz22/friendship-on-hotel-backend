<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only room inventory operations. Derive turnover status from the actual
 * checkout task and readiness decisions; never write a competing room-status flag.
 */
final class RoomInventoryStatusService
{
    /** @param array<int, int> $ids */
    public function forRooms(Hotel $hotel, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > 100 || min($ids) < 1) {
            throw ValidationException::withMessages(['roomIds' => ['Supply 1 to 100 valid room IDs.']]);
        }
        $rooms = DB::table('rooms')->where('hotel_id', $hotel->id)
            ->whereIn('id', $ids)->get(['id', 'number'])->keyBy('id');
        if ($rooms->count() !== count($ids)) {
            throw ValidationException::withMessages(['roomIds' => ['One or more rooms do not belong to this hotel.']]);
        }

        $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();

        $stays = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->whereIn('assigned_room_id', $ids)
            ->where('lifecycle_state', 'checked_in')
            ->orderByDesc('id')->get(['id','assigned_room_id','check_out'])
            ->groupBy('assigned_room_id');

        $linkIds = DB::table('reservation_turnover_links as l')
            ->join('housekeeping_tasks as t', function ($join): void {
                $join->on('t.id','=','l.housekeeping_task_id')
                    ->on('t.hotel_id','=','l.hotel_id');
            })->where('l.hotel_id', $hotel->id)->whereIn('t.room_id', $ids)
            ->select('t.room_id')->selectRaw('MAX(l.id) as latest_id')
            ->groupBy('t.room_id')->pluck('latest_id')->all();

        $turnovers = $linkIds === [] ? collect() : DB::table('reservation_turnover_links as l')
            ->join('housekeeping_tasks as t', function ($join): void {
                $join->on('t.id','=','l.housekeeping_task_id')
                    ->on('t.hotel_id','=','l.hotel_id');
            })->where('l.hotel_id', $hotel->id)->whereIn('l.id', $linkIds)
            ->get(['l.id as link_id','t.id as task_id','t.room_id','t.status','t.assigned_user_id',
                't.created_at','t.completed_at'])->keyBy('room_id');

        $lastInspectionIds = DB::table('room_readiness_inspections')
            ->where('hotel_id', $hotel->id)->whereIn('room_id', $ids)
            ->select('room_id')->selectRaw('MAX(id) as latest_id')
            ->groupBy('room_id')->pluck('latest_id')->all();
        $inspections = $lastInspectionIds === [] ? collect()
            : DB::table('room_readiness_inspections')->where('hotel_id', $hotel->id)
                ->whereIn('id', $lastInspectionIds)->get()->keyBy('room_id');

        $unfinished = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $ids)->where('status', '!=', 'done')
            ->select('room_id')->selectRaw('COUNT(*) as total')->groupBy('room_id')
            ->pluck('total', 'room_id');

        $blockIds = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $ids)->where('is_active', true)
            ->where('check_in','<=',$today)->where('check_out','>',$today)
            ->pluck('room_id')->flip();

        $maintenance = DB::table('maintenance_work_orders')->where('hotel_id',$hotel->id)
            ->whereIn('room_id',$ids)->where('affects_room',true)
            ->whereIn('status',['open','in_progress'])->pluck('room_id')->flip();

        $latestTaskIds = DB::table('housekeeping_tasks')->where('hotel_id',$hotel->id)
            ->whereIn('room_id',$ids)->select('room_id')->selectRaw('MAX(id) as id')
            ->groupBy('room_id')->pluck('id','room_id');
        $latestBlockIds = DB::table('room_inventory_blocks')->where('hotel_id',$hotel->id)
            ->whereIn('room_id',$ids)->select('room_id')->selectRaw('MAX(id) as id')
            ->groupBy('room_id')->pluck('id','room_id');
        $latestImpactIds = DB::table('maintenance_work_orders')->where('hotel_id',$hotel->id)
            ->whereIn('room_id',$ids)->where('affects_room',true)
            ->select('room_id')->selectRaw('MAX(id) as id')->groupBy('room_id')
            ->pluck('id','room_id');

        $doneTaskIds = $turnovers->filter(fn ($task): bool => $task->completed_at !== null)
            ->pluck('task_id')->all();
        $doneEvents = $doneTaskIds === [] ? collect()
            : DB::table('housekeeping_task_events')->where('hotel_id',$hotel->id)
                ->whereIn('task_id',$doneTaskIds)->where('action','updated')
                ->orderByDesc('id')->get(['task_id','actor_id','after_state'])
                ->groupBy('task_id');

        // Names are displayed only to users who can read hotel reservations.
        // Return minimal staff identity fields, never emails or guest details.
        $staffIds = [];
        foreach ($turnovers as $task) {
            if ($task->assigned_user_id !== null) $staffIds[] = (int) $task->assigned_user_id;
        }
        foreach ($inspections as $inspection) {
            $staffIds[] = (int) $inspection->inspected_by;
            if ($inspection->reviewed_by !== null) $staffIds[] = (int) $inspection->reviewed_by;
        }
        foreach ($doneEvents as $events) {
            foreach ($events as $event) $staffIds[] = (int) $event->actor_id;
        }
        $staffNames = $staffIds === [] ? collect() :
            DB::table('users')->whereIn('id', array_unique($staffIds))->pluck('name', 'id');

        $results = [];
        foreach ($ids as $id) {
            $stay = $stays->get($id)?->first();
            $turnover = $turnovers->get($id);
            $inspection = $inspections->get($id);
            $hasBlocker = $blockIds->has($id) || $maintenance->has($id)
                || (int) ($unfinished->get($id) ?? 0) > 0;
            $stale = $inspection !== null && (
                (int) ($turnover?->link_id ?? 0) > (int) $inspection->latest_turnover_link_id
                || (int) ($latestTaskIds->get($id) ?? 0) > (int) $inspection->latest_housekeeping_task_id
                || (int) ($latestBlockIds->get($id) ?? 0) > (int) $inspection->latest_inventory_block_id
                || (int) ($latestImpactIds->get($id) ?? 0) > (int) $inspection->latest_room_impact_order_id
            );
            $approved = $inspection !== null && $inspection->status === 'approved'
                && ! $stale && ! $hasBlocker;
            $cleaning = $turnover !== null && $turnover->status === 'todo' ? 'checkout_cleaning'
                : ($turnover !== null && $turnover->status === 'in_progress' ? 'cleaning_in_progress'
                    : ($turnover !== null && $turnover->status === 'done' && ! $approved
                        ? 'awaiting_inspection' : ($approved ? 'ready' : 'not_verified')));
            if (($blockIds->has($id) || $maintenance->has($id)) && $cleaning === 'ready') {
                $cleaning = 'not_verified';
            }
            $completedBy = null;
            if ($turnover !== null && $turnover->completed_at !== null) {
                foreach ($doneEvents->get($turnover->task_id, collect()) as $event) {
                    $after = json_decode($event->after_state, true);
                    if (($after['status'] ?? null) === 'done') {
                        $completedBy = (string) $event->actor_id;
                        break;
                    }
                }
            }
            $results[] = [
                'roomId' => (string) $id,
                'roomNumber' => $rooms[$id]->number,
                'occupancy' => $stay === null ? 'vacant'
                    : ($stay->check_out === $today ? 'due_for_checkout' : 'occupied'),
                'activeStayId' => $stay === null ? null : (string) $stay->id,
                'cleaning' => $cleaning,
                'turnoverTaskId' => $turnover === null ? null : (string) $turnover->task_id,
                'turnoverStatus' => $turnover?->status,
                'assignedHousekeeperId' => $turnover?->assigned_user_id === null
                    ? null : (string) $turnover->assigned_user_id,
                'assignedHousekeeperName' => $turnover?->assigned_user_id === null
                    ? null : $staffNames->get((int) $turnover->assigned_user_id),
                'cleanedById' => $completedBy,
                'cleanedByName' => $completedBy === null ? null : $staffNames->get((int) $completedBy),
                'cleanedAt' => $turnover?->completed_at,
                'inspectionId' => $inspection === null ? null : (string) $inspection->id,
                'inspectionStatus' => $inspection?->status,
                'inspectedById' => $inspection === null ? null : (string) $inspection->inspected_by,
                'inspectedByName' => $inspection === null
                    ? null : $staffNames->get((int) $inspection->inspected_by),
                'inspectedAt' => $inspection?->created_at,
                'reviewedById' => $inspection?->reviewed_by === null
                    ? null : (string) $inspection->reviewed_by,
                'reviewedByName' => $inspection?->reviewed_by === null
                    ? null : $staffNames->get((int) $inspection->reviewed_by),
                'reviewedAt' => $inspection?->reviewed_at,
                'advisoryReady' => $approved,
                'maintenanceBlocked' => $maintenance->has($id),
                'inventoryBlockedToday' => $blockIds->has($id),
            ];
        }

        return ['data' => $results, 'meta' => [
            'hotelId' => (string) $hotel->id, 'hotelDate' => $today,
            'snapshotOnly' => true, 'advisoryOnly' => true,
            'physicalOccupancyVerified' => false,
        ]];
    }
}

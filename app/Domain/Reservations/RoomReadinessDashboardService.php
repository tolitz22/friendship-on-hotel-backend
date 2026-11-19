<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 37 read-only operational snapshot. This is NOT an inventory/guest
 * authorization or future-night readiness guarantee.
 *
 * Bulk-loads hotel room activity instead of N per-room lookups. Limit prevents
 * a synchronous report from silently returning partial hotel-wide totals.
 */
final class RoomReadinessDashboardService
{
    public const MAX_ROOMS = 1000;

    public function overview(Hotel $hotel, int $days, int $page, int $perPage): array
    {
        $asOf = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $through = (new \DateTimeImmutable($asOf))->modify('+'.$days.' days')->format('Y-m-d');

        $rooms = DB::table('rooms')->where('hotel_id', $hotel->id)
            ->orderBy('id')->limit(self::MAX_ROOMS + 1)
            ->get(['id', 'number', 'room_type_id']);
        if ($rooms->count() > self::MAX_ROOMS) {
            throw new HttpException(422, 'Hotel exceeds the synchronous readiness dashboard limit. Use an approved paginated aggregation before reporting hotel-wide totals.');
        }

        $roomIds = $rooms->pluck('id')->all();
        $latestInspectionIds = DB::table('room_readiness_inspections')
            ->where('hotel_id', $hotel->id)
            ->select('room_id')->selectRaw('MAX(id) as newest_id')
            ->groupBy('room_id')->pluck('newest_id')->all();
        $inspections = $latestInspectionIds === []
            ? collect()
            : DB::table('room_readiness_inspections')
                ->where('hotel_id', $hotel->id)
                ->whereIn('id', $latestInspectionIds)->get()->keyBy('room_id');

        $tasks = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $roomIds)
            ->select('room_id')
            ->selectRaw('MAX(id) as newest_id')
            ->selectRaw("SUM(CASE WHEN status != 'done' THEN 1 ELSE 0 END) as unfinished")
            ->groupBy('room_id')->get()->keyBy('room_id');

        $impactOrders = DB::table('maintenance_work_orders')
            ->where('hotel_id', $hotel->id)->whereIn('room_id', $roomIds)
            ->where('affects_room', true)
            ->select('room_id')->selectRaw('MAX(id) as newest_id')
            ->selectRaw("SUM(CASE WHEN status IN ('open','in_progress') THEN 1 ELSE 0 END) as active_count")
            ->groupBy('room_id')->get()->keyBy('room_id');

        $blockIds = DB::table('room_inventory_blocks')
            ->where('hotel_id', $hotel->id)->whereIn('room_id', $roomIds)
            ->select('room_id')->selectRaw('MAX(id) as newest_id')
            ->groupBy('room_id')->get()->keyBy('room_id');
        $todayBlocks = DB::table('room_inventory_blocks')
            ->where('hotel_id', $hotel->id)->whereIn('room_id', $roomIds)
            ->where('is_active', true)
            ->where('check_in', '<=', $asOf)->where('check_out', '>', $asOf)
            ->pluck('room_id')->flip();

        $latestLinks = DB::table('reservation_turnover_links as links')
            ->join('housekeeping_tasks as t', function ($join): void {
                $join->on('t.id', '=', 'links.housekeeping_task_id')
                    ->on('t.hotel_id', '=', 'links.hotel_id');
            })->where('links.hotel_id', $hotel->id)
            ->whereIn('t.room_id', $roomIds)
            ->select('t.room_id')->selectRaw('MAX(links.id) as newest_id')
            ->groupBy('t.room_id')->pluck('newest_id')->all();
        $turnovers = $latestLinks === [] ? collect()
            : DB::table('reservation_turnover_links as links')
                ->join('housekeeping_tasks as t', function ($join): void {
                    $join->on('t.id', '=', 'links.housekeeping_task_id')
                        ->on('t.hotel_id', '=', 'links.hotel_id');
                })
                ->where('links.hotel_id', $hotel->id)
                ->whereIn('links.id', $latestLinks)
                ->get(['links.id', 't.room_id', 't.status'])
                ->keyBy('room_id');

        // Operational shortcuts are derived from actual active records, not
        // the newest historical record, which may already be completed.
        $openTasks = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $roomIds)->where('status', '!=', 'done')
            ->orderByDesc('id')->get(['id','room_id'])->unique('room_id')->keyBy('room_id');
        $openOrders = DB::table('maintenance_work_orders')->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $roomIds)->where('affects_room', true)
            ->whereIn('status', ['open','in_progress'])
            ->orderByDesc('id')->get(['id','room_id'])->unique('room_id')->keyBy('room_id');
        $activeBlocks = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $roomIds)->where('is_active', true)
            ->where('check_in', '<=', $asOf)->where('check_out', '>', $asOf)
            ->orderByDesc('id')->get(['id','room_id'])->unique('room_id')->keyBy('room_id');

        $roomStatuses = [];
        $counts = ['totalRooms' => $rooms->count(), 'approvedCurrent' => 0,
            'awaitingReview' => 0, 'needsInspection' => 0, 'blockedOrStale' => 0];
        foreach ($rooms as $room) {
            $id = (int) $room->id;
            $inspection = $inspections->get($id);
            $task = $tasks->get($id);
            $impact = $impactOrders->get($id);
            $block = $blockIds->get($id);
            $turnover = $turnovers->get($id);
            $blockers = [
                'activeInventoryBlockToday' => $todayBlocks->has($id),
                'activeRoomImpactMaintenance' => $impact !== null && (int) $impact->active_count > 0,
                'latestTurnoverIncomplete' => $turnover !== null && $turnover->status !== 'done',
                'newTurnoverSinceInspection' => $inspection !== null && $turnover !== null
                    && (int) $turnover->id > (int) $inspection->latest_turnover_link_id,
                'unfinishedHousekeeping' => $task !== null && (int) $task->unfinished > 0,
                'newRoomWorkSinceInspection' => $inspection !== null && (
                    (int) ($task?->newest_id ?? 0) > (int) $inspection->latest_housekeeping_task_id
                    || (int) ($impact?->newest_id ?? 0) > (int) $inspection->latest_room_impact_order_id
                    || (int) ($block?->newest_id ?? 0) > (int) $inspection->latest_inventory_block_id
                ),
            ];
            $hasBlocker = in_array(true, $blockers, true);
            $status = $inspection === null ? 'needs_inspection'
                : ($inspection->status === 'approved' && ! $hasBlocker
                    ? 'approved_current' : ($inspection->status === 'submitted'
                        ? 'needs_inspection' : 'blocked_or_stale'));
            $counts[match ($status) {
                'needs_inspection' => 'needsInspection',
                'awaiting_review' => 'awaitingReview',
                'approved_current' => 'approvedCurrent',
                default => 'blockedOrStale',
            }]++;
            $roomStatuses[$id] = [
                'roomId' => (string) $id,
                'roomNumber' => $room->number,
                'roomTypeId' => (string) $room->room_type_id,
                'status' => $status,
                'latestInspectionId' => $inspection === null ? null : (string) $inspection->id,
                'inspectionStatus' => $inspection?->status,
                'openHousekeepingTaskId' => $openTasks->has($id) ? (string) $openTasks->get($id)->id : null,
                'activeMaintenanceOrderId' => $openOrders->has($id) ? (string) $openOrders->get($id)->id : null,
                'activeInventoryBlockId' => $activeBlocks->has($id) ? (string) $activeBlocks->get($id)->id : null,
                'blockers' => $blockers,
            ];
        }

        $arrivals = DB::table('reservation_records as r')
            ->leftJoin('guests as guest', function ($join): void {
                $join->on('guest.id', '=', 'r.guest_id')
                    ->on('guest.hotel_id', '=', 'r.hotel_id');
            })
            ->leftJoin('rooms as rm', function ($join): void {
                $join->on('rm.id', '=', 'r.assigned_room_id')
                    ->on('rm.hotel_id', '=', 'r.hotel_id');
            })
            ->where('r.hotel_id', $hotel->id)
            ->where('r.lifecycle_state', 'confirmed')
            ->where('r.check_in', '>=', $asOf)->where('r.check_in', '<', $through)
            ->orderBy('r.check_in')->orderBy('r.id')
            ->limit(2001)
            ->get(['r.id', 'r.reference', 'r.check_in', 'r.check_out',
                'r.assigned_room_id', 'rm.number as room_number',
                'guest.first_name', 'guest.last_name']);
        if ($arrivals->count() > 2000) {
            throw new HttpException(422, 'Upcoming arrival count exceeds the synchronous readiness dashboard limit. No partial arrival totals are provided.');
        }
        $arrivalResults = $arrivals->map(function (object $arrival) use ($roomStatuses): array {
            $roomId = $arrival->assigned_room_id === null ? null : (int) $arrival->assigned_room_id;
            $room = $roomId === null ? null : ($roomStatuses[$roomId] ?? null);
            $risk = $roomId === null ? 'unassigned_room'
                : ($room === null ? 'room_record_missing'
                    : ($room['status'] === 'approved_current' ? 'no_current_readiness_issue' : 'inspection_or_blocker'));
            return [
                'bookingId' => (string) $arrival->id,
                'reference' => $arrival->reference,
                'guestName' => $arrival->first_name === null ? null : trim($arrival->first_name.' '.$arrival->last_name),
                'checkIn' => $arrival->check_in,
                'checkOut' => $arrival->check_out,
                'assignedRoomId' => $roomId === null ? null : (string) $roomId,
                'roomNumber' => $arrival->room_number,
                'risk' => $risk,
                'roomStatus' => $room['status'] ?? null,
                'roomBlockers' => $room['blockers'] ?? null,
                'openHousekeepingTaskId' => $room['openHousekeepingTaskId'] ?? null,
                'activeMaintenanceOrderId' => $room['activeMaintenanceOrderId'] ?? null,
                'activeInventoryBlockId' => $room['activeInventoryBlockId'] ?? null,
            ];
        })->all();

        // Pagination counts reflect the entire eligible hotel, never a partial
        // page. Query is bounded to 1,000 rooms + up to 14 days of arrivals.
        $roomRows = array_values($roomStatuses);
        $pageRooms = array_slice($roomRows, ($page - 1) * $perPage, $perPage);
        return [
            'data' => [
                'hotelId' => (string) $hotel->id,
                'asOfHotelDate' => $asOf,
                'arrivalWindowDays' => $days,
                'arrivalWindowEndExclusive' => $through,
                'enforceAtCheckIn' => app(ReadinessWriteGuard::class)->enabled($hotel),
                'summary' => $counts,
                'rooms' => $pageRooms,
                'arrivals' => $arrivalResults,
            ],
            'meta' => [
                'roomPage' => $page,
                'roomPerPage' => $perPage,
                'roomTotalPages' => (int) ceil(count($roomRows) / $perPage),
                'arrivalTotal' => count($arrivalResults),
                'snapshotOnly' => true,
                'futureRoomReadinessGuaranteed' => false,
                'inventoryGuaranteed' => false,
                'guestOrPaymentVerified' => false,
            ],
        ];
    }
}

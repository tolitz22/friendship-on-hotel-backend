<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, reservation-authorized PMS room rack.
 * Occupancy, physical readiness, housekeeping and restrictions remain separate
 * sources; this endpoint intentionally does NOT calculate booking availability.
 */
final class RoomRackService
{
    public function snapshot(Hotel $hotel, int $page, int $perPage, int $days): array
    {
        $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $report = app(RoomReadinessDashboardService::class)->overview($hotel, $days, $page, $perPage);
        $readiness = $report['data']['rooms'];
        $ids = array_map(static fn (array $r): int => (int) $r['roomId'], $readiness);

        $catalog = $ids === [] ? collect() : DB::table('rooms as r')
            ->join('room_types as t', function ($join): void {
                $join->on('t.id', '=', 'r.room_type_id')->on('t.hotel_id', '=', 'r.hotel_id');
            })->where('r.hotel_id', $hotel->id)->whereIn('r.id', $ids)
            ->get(['r.id', 'r.number', 'r.floor_number', 'r.room_type_id', 't.name as type_name', 't.max_occupancy'])->keyBy('id');

        $statuses = $ids === [] ? collect() : collect(
            app(RoomInventoryStatusService::class)->forRooms($hotel, $ids)['data']
        )->keyBy('roomId');

        $stays = $ids === [] ? collect() : DB::table('reservation_records as booking')
            ->leftJoin('guests as guest', function ($join): void {
                $join->on('guest.id', '=', 'booking.guest_id')
                    ->on('guest.hotel_id', '=', 'booking.hotel_id');
            })->where('booking.hotel_id', $hotel->id)
            ->whereIn('booking.assigned_room_id', $ids)
            ->where(function ($query) use ($today): void {
                $query->where('booking.lifecycle_state', 'checked_in')
                    ->orWhere(function ($future) use ($today): void {
                        $future->where('booking.lifecycle_state', 'confirmed')
                            ->where('booking.check_out', '>', $today);
                    });
            })
            ->orderBy('booking.check_in')->orderBy('booking.id')
            ->get(['booking.id', 'booking.assigned_room_id', 'booking.reference',
                'booking.lifecycle_state', 'booking.check_in', 'booking.check_out',
                'guest.first_name', 'guest.last_name'])->groupBy('assigned_room_id');

        $rooms = [];
        foreach ($readiness as $row) {
            $id = (int) $row['roomId'];
            $room = $catalog->get($id);
            $status = $statuses->get((string) $id);
            if ($room === null || $status === null) {
                throw new \RuntimeException('Room rack source records did not reconcile.');
            }
            $roomStays = $stays->get($id, collect());
            $current = $roomStays->firstWhere('lifecycle_state', 'checked_in');
            $upcoming = $roomStays->firstWhere('lifecycle_state', 'confirmed');
            $present = static fn (?object $record): ?array => $record === null ? null : [
                'bookingId' => (string) $record->id, 'reference' => $record->reference,
                'guestName' => $record->first_name === null ? null :
                    trim($record->first_name.' '.$record->last_name),
                'checkIn' => (string) $record->check_in,
                'checkOut' => (string) $record->check_out,
            ];

            $rooms[] = [
                'roomId' => $row['roomId'],
                'roomNumber' => $row['roomNumber'],
                'roomTypeName' => $room->type_name,
                'roomTypeId' => (string) $room->room_type_id,
                'maxOccupancy' => (int) $room->max_occupancy,
                'floor' => $room->floor_number === null ? null : (int) $room->floor_number,
                'occupancy' => $status['occupancy'],
                'cleaning' => $status['cleaning'],
                'advisoryReady' => $status['advisoryReady'],
                'inspectionStatus' => $row['inspectionStatus'],
                'inspectionId' => $row['latestInspectionId'],
                'readinessStatus' => $row['status'],
                'blockers' => $row['blockers'],
                'openHousekeepingTaskId' => $row['openHousekeepingTaskId'],
                'activeMaintenanceOrderId' => $row['activeMaintenanceOrderId'],
                'activeInventoryBlockId' => $row['activeInventoryBlockId'],
                'cleanedAt' => $status['cleanedAt'],
                'inspectedAt' => $status['inspectedAt'],
                'reviewedAt' => $status['reviewedAt'],
                'currentStay' => $present($current),
                'upcomingStay' => $present($upcoming),
            ];
        }

        // Counts are for the entire hotel, not just the visible room page.
        $occupied = DB::table('reservation_records as booking')
            ->join('rooms as room', function ($join): void {
                $join->on('room.id', '=', 'booking.assigned_room_id')
                    ->on('room.hotel_id', '=', 'booking.hotel_id');
            })->where('booking.hotel_id', $hotel->id)
            ->where('booking.lifecycle_state', 'checked_in')
            ->distinct()->count('room.id');

        $arrivalsToday = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->where('lifecycle_state', 'confirmed')
            ->where('check_in', $today)->count();
        $departuresToday = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->where('lifecycle_state', 'checked_in')
            ->where('check_out', $today)->count();
        $cleaningRooms = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
            ->whereIn('type', ['checkout_clean', 'stayover'])
            ->where('status', '!=', 'done')->distinct()->count('room_id');
        $legacyPendingInspections = DB::table('room_readiness_inspections')
            ->where('hotel_id', $hotel->id)
            ->whereIn('id', DB::table('room_readiness_inspections')
                ->where('hotel_id', $hotel->id)
                ->selectRaw('MAX(id)')->groupBy('room_id'))
            ->where('status', 'submitted')->count();
        $maintRooms = DB::table('maintenance_work_orders')
            ->where('hotel_id', $hotel->id)->where('affects_room', true)
            ->whereIn('status', ['open', 'in_progress'])->pluck('room_id')->all();
        $blockRooms = DB::table('room_inventory_blocks')
            ->where('hotel_id', $hotel->id)->where('is_active', true)
            ->where('check_in', '<=', $today)->where('check_out', '>', $today)
            ->pluck('room_id')->all();
        $blockedRooms = count(array_unique(array_merge($maintRooms, $blockRooms)));

        return [
            'data' => [
                'hotelId' => (string) $hotel->id,
                'hotelDate' => $today,
                'enforcedAtCheckIn' => $report['data']['enforceAtCheckIn'],
                'rooms' => $rooms,
                'arrivals' => $report['data']['arrivals'],
                'summary' => [
                    'totalRooms' => $report['data']['summary']['totalRooms'],
                    'pmsVacantRooms' => max(0, $report['data']['summary']['totalRooms'] - $occupied),
                    'pmsOccupiedRooms' => $occupied,
                    'arrivalsToday' => $arrivalsToday,
                    'departuresToday' => $departuresToday,
                    'roomsNeedingCleaning' => $cleaningRooms,
                    'legacyPendingInspections' => $legacyPendingInspections,
                    'activelyBlockedRooms' => $blockedRooms,
                ],
            ],
            'meta' => [
                'page' => $report['meta']['roomPage'],
                'perPage' => $report['meta']['roomPerPage'],
                'totalPages' => $report['meta']['roomTotalPages'],
                'total' => $report['data']['summary']['totalRooms'],
                'snapshotOnly' => true,
                'availabilityGuaranteed' => false,
                'physicalOccupancyVerified' => false,
                'financialSettlementVerified' => false,
            ],
        ];
    }
}

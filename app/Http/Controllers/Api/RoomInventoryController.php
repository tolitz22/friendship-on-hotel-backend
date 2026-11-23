<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomInventoryService;
use App\Domain\Reservations\StayPeriod;
use Carbon\CarbonImmutable;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

final class RoomInventoryController
{
    public function availability(Request $request, Hotel $hotel, RoomInventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'roomTypeId' => ['required', 'integer', 'min:1'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
        ]);
        return response()->json([
            'data' => $inventory->availability(
                $hotel, (int) $data['roomTypeId'],
                $inventory->stay($data['checkIn'], $data['checkOut'])
            ),
            'meta' => [
                'scope' => 'registered_room_type_inventory',
                'housekeepingReadinessVerified' => false,
                'pricingIncluded' => false,
            ],
        ]);
    }

    public function forecast(Hotel $hotel, RoomInventoryService $inventory): JsonResponse
    {
        $today = CarbonImmutable::today($hotel->timezone ?: 'Asia/Manila');
        $stay = new StayPeriod($today->toDateString(), $today->addDays(14)->toDateString());
        $nights = [];
        for ($offset = 0; $offset < 14; $offset++) {
            $date = $today->addDays($offset)->toDateString();
            $nights[$date] = [
                'date' => $date, 'sellable' => 0, 'reserved' => 0, 'blocked' => 0, 'groupHeld' => 0,
                'remaining' => 0, 'arrivals' => 0, 'departures' => 0, 'overcommitted' => false,
            ];
        }

        foreach ($hotel->roomTypes()->pluck('id') as $typeId) {
            foreach ($inventory->availability($hotel, (int) $typeId, $stay)['nights'] as $night) {
                $row = &$nights[$night['date']];
                $row['sellable'] += max(0, $night['physicalRooms'] - $night['blocked']);
                foreach (['reserved', 'blocked', 'groupHeld', 'remaining'] as $field) {
                    $row[$field] += $night[$field];
                }
                $row['overcommitted'] = $row['overcommitted'] || $night['overcommitted'];
                unset($row);
            }
        }

        $movements = DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->whereIn('lifecycle_state', ['confirmed', 'checked_in']);
        foreach (['arrivals' => 'check_in', 'departures' => 'check_out'] as $field => $column) {
            $query = clone $movements;
            if ($field === 'arrivals') {
                $query->where('lifecycle_state', 'confirmed');
            }
            $countByDate = $query
                ->where($column, '>=', $stay->arrival)->where($column, '<', $stay->departure)
                ->select($column)->selectRaw('COUNT(*) AS booking_count')->groupBy($column)
                ->pluck('booking_count', $column);
            foreach ($countByDate as $date => $count) {
                $nights[$date][$field] = (int) $count;
            }
        }

        return response()->json([
            'data' => ['hotelId' => (string) $hotel->id, 'nights' => array_values($nights)],
        ]);
    }

    public function blocks(Request $request, Hotel $hotel): JsonResponse
    {
        $params = $request->validate([
            'roomId' => ['sometimes', 'integer', 'min:1'],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id);
        if (isset($params['roomId'])) {
            $query->where('room_id', (int) $params['roomId']);
        }
        if (array_key_exists('active', $params)) {
            $query->where('is_active', (bool) $params['active']);
        }

        $page = $query->orderByDesc('id')->paginate((int) ($params['per_page'] ?? 20));
        return response()->json([
            'data' => $page->getCollection()->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'roomId' => (string) $row->room_id,
                'roomTypeId' => (string) $row->room_type_id,
                'checkIn' => $row->check_in,
                'checkOut' => $row->check_out,
                'reason' => $row->reason,
                'active' => (bool) $row->is_active,
                'createdBy' => (string) $row->created_by,
                'releasedBy' => $row->released_by === null ? null : (string) $row->released_by,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }

    public function block(Request $request, Hotel $hotel, RoomInventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'roomId' => ['required', 'integer', 'min:1'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        if (trim($data['reason']) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        return response()->json(['data' => $inventory->block($hotel, (int) $request->user()->id, $data)], 201);
    }

    public function release(Request $request, Hotel $hotel, string $block, RoomInventoryService $inventory): JsonResponse
    {
        return response()->json(['data' => $inventory->release(
            $hotel, (int) $request->user()->id, (int) $block
        )]);
    }

    public function assignmentOptions(Request $request, Hotel $hotel, string $booking, RoomInventoryService $inventory): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($inventory->assignmentOptions($hotel, (int) $booking,
            (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 24)));
    }

    public function assign(Request $request, Hotel $hotel, string $booking, RoomInventoryService $inventory): JsonResponse
    {
        $data = $request->validate(['roomId' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $inventory->assign(
            $hotel, (int) $request->user()->id, (int) $booking, (int) $data['roomId']
        )]);
    }
}

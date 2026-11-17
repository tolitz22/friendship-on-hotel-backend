<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomInventoryService;
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

    public function assign(Request $request, Hotel $hotel, string $booking, RoomInventoryService $inventory): JsonResponse
    {
        $data = $request->validate(['roomId' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $inventory->assign(
            $hotel, (int) $request->user()->id, (int) $booking, (int) $data['roomId']
        )]);
    }
}

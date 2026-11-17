<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomInventoryService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

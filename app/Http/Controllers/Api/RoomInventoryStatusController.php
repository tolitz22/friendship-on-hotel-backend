<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomInventoryStatusService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoomInventoryStatusController
{
    public function __invoke(Request $request, Hotel $hotel, RoomInventoryStatusService $service): JsonResponse
    {
        $input = $request->validate([
            'room_ids' => ['required', 'string', 'max:1200', 'regex:/^[1-9][0-9]*(,[1-9][0-9]*)*$/'],
        ]);
        $ids = explode(',', $input['room_ids']);
        if (count($ids) > 100 || count($ids) !== count(array_unique($ids))) {
            return response()->json(['message' => 'Provide up to 100 distinct room IDs.'], 422);
        }
        return response()->json($service->forRooms($hotel, array_map('intval', $ids)));
    }
}

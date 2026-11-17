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
            'room_ids' => ['required', 'array', 'min:1', 'max:100'],
            'room_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        return response()->json($service->forRooms($hotel, $input['room_ids']));
    }
}

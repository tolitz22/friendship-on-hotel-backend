<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomCatalogController
{
    public function roomTypes(Hotel $hotel): JsonResponse
    {
        $types = $hotel->roomTypes()->orderBy('name')->get();

        return response()->json(['data' => $types->map(fn ($type): array => [
            'id' => (string) $type->id,
            'hotelId' => (string) $type->hotel_id,
            'name' => $type->name,
            'maxOccupancy' => $type->max_occupancy,
        ])->values()]);
    }

    public function rooms(Request $request, Hotel $hotel): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $results = $hotel->rooms()->with('roomType:id,name,max_occupancy')
            ->orderBy('number')->paginate($perPage);

        return response()->json([
            'data' => $results->getCollection()->map(fn ($room): array => [
                'id' => (string) $room->id,
                'hotelId' => (string) $room->hotel_id,
                'number' => $room->number,
                'floor' => $room->floor_number,
                'roomTypeId' => (string) $room->room_type_id,
                'roomTypeName' => $room->roomType->name,
                'maxOccupancy' => $room->roomType->max_occupancy,
            ])->values(),
            'meta' => [
                'page' => $results->currentPage(),
                'perPage' => $results->perPage(),
                'total' => $results->total(),
                'totalPages' => $results->lastPage(),
            ],
        ]);
    }
}

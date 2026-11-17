<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;

final class PropertyCatalogSummaryController
{
    /**
     * Counts the registered catalogue, not occupancy, readiness or availability.
     */
    public function __invoke(Hotel $hotel): JsonResponse
    {
        $roomCount = $hotel->rooms()->count();
        $roomTypeCount = $hotel->roomTypes()->count();

        $floors = $hotel->rooms()
            ->selectRaw('floor_number, COUNT(*) as room_count')
            ->groupBy('floor_number')
            ->orderBy('floor_number')
            ->get()
            ->map(fn ($floor): array => [
                'number' => $floor->floor_number === null ? null : (int) $floor->floor_number,
                'roomCount' => (int) $floor->room_count,
            ])->values();

        $types = $hotel->roomTypes()
            ->withCount('rooms')
            ->orderBy('name')
            ->get()
            ->map(fn ($type): array => [
                'id' => (string) $type->id,
                'name' => $type->name,
                'maxOccupancy' => (int) $type->max_occupancy,
                'roomCount' => (int) $type->rooms_count,
            ])->values();

        return response()->json([
            'data' => [
                'hotelId' => (string) $hotel->id,
                'totalRooms' => $roomCount,
                'totalRoomTypes' => $roomTypeCount,
                'floors' => $floors,
                'roomTypes' => $types,
            ],
            'meta' => [
                'isAvailability' => false,
                'isOccupancy' => false,
                'scope' => 'physical_room_catalog',
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomCatalogController
{
    public function createRoomType(Request $request, Hotel $hotel): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120', Rule::unique('room_types', 'name')->where('hotel_id', $hotel->id)],
            'maxOccupancy' => ['required', 'integer', 'min:1', 'max:65535'],
        ])->validate();

        $type = $hotel->roomTypes()->create([
            'name' => $data['name'],
            'max_occupancy' => $data['maxOccupancy'],
        ]);

        return response()->json(['data' => [
            'id' => (string) $type->id,
            'hotelId' => (string) $type->hotel_id,
            'name' => $type->name,
            'maxOccupancy' => $type->max_occupancy,
        ]], 201);
    }

    public function createRoom(Request $request, Hotel $hotel): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'roomTypeId' => ['required', 'integer'],
            'number' => ['required', 'string', 'max:32', Rule::unique('rooms', 'number')->where('hotel_id', $hotel->id)],
            'floor' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ])->validate();

        $type = $hotel->roomTypes()->find($data['roomTypeId']);

        if (! $type) {
            throw ValidationException::withMessages(['roomTypeId' => ['The room type does not belong to this property.']]);
        }

        $room = $hotel->rooms()->create([
            'room_type_id' => $type->id,
            'number' => $data['number'],
            'floor_number' => $data['floor'] ?? null,
        ]);

        return response()->json(['data' => [
            'id' => (string) $room->id,
            'hotelId' => (string) $hotel->id,
            'number' => $room->number,
            'floor' => $room->floor_number,
            'roomTypeId' => (string) $type->id,
            'roomTypeName' => $type->name,
            'maxOccupancy' => $type->max_occupancy,
        ]], 201);
    }

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

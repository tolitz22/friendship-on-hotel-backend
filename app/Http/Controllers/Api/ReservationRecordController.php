<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReservationRecordController
{
    /**
     * Read a specific saved record for Front Desk deep links. The hotel scope
     * is mandatory: never reveal a booking by its global ID alone.
     */
    public function show(Hotel $hotel, string $booking): JsonResponse
    {
        $record = $hotel->reservationRecords()->with(['roomType:id,name', 'assignedRoom:id,number'])
            ->whereKey($booking)->firstOrFail();
        $guest = DB::table('guests')->where('hotel_id', $hotel->id)
            ->where('id', $record->guest_id)->first(['first_name', 'last_name']);

        return response()->json(['data' => [
            'id' => (string) $record->id,
            'hotelId' => (string) $record->hotel_id,
            'reference' => $record->reference,
            'guestName' => $guest === null ? null : trim($guest->first_name.' '.$guest->last_name),
            'roomTypeName' => $record->roomType?->name,
            'assignedRoomNumber' => $record->assignedRoom?->number,
            'roomTypeId' => (string) $record->room_type_id,
            'assignedRoomId' => $record->assigned_room_id === null ? null : (string) $record->assigned_room_id,
            'checkIn' => $record->check_in->format('Y-m-d'),
            'checkOut' => $record->check_out->format('Y-m-d'),
            'lifecycleState' => $record->lifecycle_state,
        ]]);
    }

    /**
     * Structural reservation records only; this is not a booking or availability API.
     */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 20);
        $records = $hotel->reservationRecords()
            ->with(['roomType:id,name', 'assignedRoom:id,number'])
            ->orderBy('check_in')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->getCollection()->map(fn ($record): array => [
                'id' => (string) $record->id,
                'hotelId' => (string) $record->hotel_id,
                'reference' => $record->reference,
                'roomTypeName' => $record->roomType?->name,
                'assignedRoomNumber' => $record->assignedRoom?->number,
                'roomTypeId' => (string) $record->room_type_id,
                'assignedRoomId' => $record->assigned_room_id !== null ? (string) $record->assigned_room_id : null,
                'checkIn' => $record->check_in->format('Y-m-d'),
                'checkOut' => $record->check_out->format('Y-m-d'),
                'lifecycleState' => $record->lifecycle_state,
            ])->values(),
            'meta' => [
                'page' => $records->currentPage(),
                'perPage' => $records->perPage(),
                'total' => $records->total(),
                'totalPages' => $records->lastPage(),
                'isAvailability' => false,
            ],
        ]);
    }
}

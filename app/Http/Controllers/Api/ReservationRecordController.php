<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReservationRecordController
{
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
            ->orderBy('check_in')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->getCollection()->map(fn ($record): array => [
                'id' => (string) $record->id,
                'hotelId' => (string) $record->hotel_id,
                'reference' => $record->reference,
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

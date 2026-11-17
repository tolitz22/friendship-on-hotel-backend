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
            'search' => ['sometimes', 'string', 'max:100'],
            'filter' => ['sometimes', 'in:all,needs_room,arrivals,in_house,completed'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 20);
        $query = $hotel->reservationRecords()
            ->with(['roomType:id,name', 'assignedRoom:id,number']);
        $filter = $data['filter'] ?? 'all';
        if ($filter === 'needs_room') {
            $query->where('lifecycle_state', 'confirmed')->whereNull('assigned_room_id');
        } elseif ($filter === 'arrivals') {
            $query->where('lifecycle_state', 'confirmed');
        } elseif ($filter === 'in_house') {
            $query->where('lifecycle_state', 'checked_in');
        } elseif ($filter === 'completed') {
            $query->whereIn('lifecycle_state', ['checked_out', 'cancelled']);
        }

        $search = trim((string) ($data['search'] ?? ''));
        if ($search !== '') {
            // Literal LIKE matching. A guest/room cannot leak across hotels.
            $like = '%'.addcslashes($search, '%_\\').'%';
            $fullGuestName = DB::connection()->getDriverName() === 'mysql'
                ? "CONCAT(guests.first_name, ' ', guests.last_name)"
                : "(guests.first_name || ' ' || guests.last_name)";
            $query->where(function ($q) use ($hotel, $like, $search, $fullGuestName): void {
                $q->where('reference', 'like', $like);
                if (ctype_digit($search) && (int) $search > 0) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhereHas('assignedRoom', fn ($rooms) =>
                    $rooms->where('hotel_id', $hotel->id)->where('number', 'like', $like));
                $q->orWhereExists(function ($guestQuery) use ($hotel, $like, $fullGuestName): void {
                    $guestQuery->selectRaw('1')->from('guests')
                        ->whereColumn('guests.id', 'reservation_records.guest_id')
                        ->where('guests.hotel_id', $hotel->id)
                        ->whereRaw($fullGuestName.' LIKE ?', [$like]);
                });
            });
        }

        $records = $query->orderBy('check_in')->orderBy('id')->paginate($perPage);
        $guestNames = DB::table('guests')
            ->where('hotel_id', $hotel->id)
            ->whereIn('id', $records->getCollection()->pluck('guest_id')->filter()->all())
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return response()->json([
            'data' => $records->getCollection()->map(fn ($record): array => [
                'id' => (string) $record->id,
                'hotelId' => (string) $record->hotel_id,
                'reference' => $record->reference,
                'guestName' => isset($guestNames[$record->guest_id])
                    ? trim($guestNames[$record->guest_id]->first_name.' '.$guestNames[$record->guest_id]->last_name)
                    : null,
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

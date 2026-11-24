<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Carbon\CarbonImmutable;
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
            ->where('id', $record->guest_id)->first(['first_name', 'last_name', 'phone']);
        $early = DB::table('reservation_early_check_ins')
            ->where('hotel_id', $hotel->id)->where('reservation_record_id', $record->id)->first();


        return response()->json(['data' => [
            'id' => (string) $record->id,
            'hotelId' => (string) $record->hotel_id,
            'reference' => $record->reference,
            'guestName' => $guest === null ? null : trim($guest->first_name.' '.$guest->last_name),
            'guestPhone' => $guest?->phone,
            'roomTypeName' => $record->roomType?->name,
            'assignedRoomNumber' => $record->assignedRoom?->number,
            'roomTypeId' => (string) $record->room_type_id,
            'assignedRoomId' => $record->assigned_room_id === null ? null : (string) $record->assigned_room_id,
            'checkIn' => $record->check_in->format('Y-m-d'),
            'checkOut' => $record->check_out->format('Y-m-d'),
            'lifecycleState' => $record->lifecycle_state,
                'createdAt' => $record->created_at?->toISOString(),
                'updatedAt' => $record->updated_at?->toISOString(),
                'createdDate' => $record->created_at?->copy()->setTimezone($hotel->timezone ?: 'Asia/Manila')->format('Y-m-d'),
            'earlyCheckIn' => $early === null ? null : [
                'scheduledCheckIn' => $early->scheduled_check_in,
                'actualCheckInDate' => $early->actual_check_in_date,
                'actualCheckedInAt' => $early->actual_checked_in_at,
                'approvalRequestId' => (string) $early->approval_request_id,
                'requestedBy' => (string) $early->requested_by,
                'approvedBy' => (string) $early->approved_by,
                'checkedInBy' => (string) $early->checked_in_by,
                'reason' => $early->reason,
                'managerNote' => $early->manager_note,
            ],
        ]]);
    }

    /**
     * Structural reservation records only; this is not a booking or availability API.
     */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'date_field' => ['sometimes', 'in:created_at,updated_at,check_in,check_out,stay'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'sort_by' => ['sometimes', 'in:created_at,updated_at,check_in,check_out'],
            'sort_dir' => ['sometimes', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'filter' => ['sometimes', 'in:all,needs_room,arrivals,in_house,completed,checked_out,cancelled'],
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
        } elseif ($filter === 'checked_out' || $filter === 'cancelled') {
            $query->where('lifecycle_state', $filter);
        }

        $search = trim((string) ($data['search'] ?? ''));
        if ($search !== '') {
            // Literal LIKE matching. A guest/room cannot leak across hotels.
            $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $fullGuestName = DB::connection()->getDriverName() === 'mysql'
                ? "CONCAT(guests.first_name, ' ', guests.last_name)"
                : "(guests.first_name || ' ' || guests.last_name)";
            $query->where(function ($q) use ($hotel, $like, $search, $fullGuestName): void {
                $q->whereRaw('LOWER(reservation_records.reference) LIKE ?', [$like]);
                if (ctype_digit($search) && (int) $search > 0) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhereHas('assignedRoom', fn ($rooms) =>
                    $rooms->where('hotel_id', $hotel->id)->whereRaw('LOWER(rooms.number) LIKE ?', [$like]));
                $q->orWhereExists(function ($guestQuery) use ($hotel, $like, $fullGuestName): void {
                    $guestQuery->selectRaw('1')->from('guests')
                        ->whereColumn('guests.id', 'reservation_records.guest_id')
                        ->where('guests.hotel_id', $hotel->id)
                        ->whereRaw('LOWER('.$fullGuestName.') LIKE ?', [$like]);
                });
            });
        }

        $field = $data['date_field'] ?? 'created_at';
        $from = $data['date_from'] ?? null;
        $to = $data['date_to'] ?? null;
        if ($field === 'stay') {
            if ($from !== null) $query->where('check_out', '>', $from);
            if ($to !== null) $query->where('check_in', '<=', $to);
        } elseif (in_array($field, ['created_at', 'updated_at'], true)) {
            $timezone = $hotel->timezone ?: 'Asia/Manila';
            if ($from !== null) $query->where($field, '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
            if ($to !== null) $query->where($field, '<', CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay()->utc());
        } else {
            if ($from !== null) $query->where($field, '>=', $from);
            if ($to !== null) $query->where($field, '<=', $to);
        }
        $direction = $data['sort_dir'] ?? 'desc';
        $records = $query->orderBy($data['sort_by'] ?? 'created_at', $direction)->orderBy('id', $direction)->paginate($perPage);
        $guestNames = DB::table('guests')
            ->where('hotel_id', $hotel->id)
            ->whereIn('id', $records->getCollection()->pluck('guest_id')->filter()->all())
            ->get(['id', 'first_name', 'last_name', 'phone'])->keyBy('id');
        $earlyRecords = DB::table('reservation_early_check_ins')
            ->where('hotel_id', $hotel->id)
            ->whereIn('reservation_record_id', $records->getCollection()->pluck('id')->all())
            ->get()->keyBy('reservation_record_id');

        return response()->json([
            'data' => $records->getCollection()->map(fn ($record): array => [
                'id' => (string) $record->id,
                'hotelId' => (string) $record->hotel_id,
                'reference' => $record->reference,
                'guestName' => isset($guestNames[$record->guest_id])
                    ? trim($guestNames[$record->guest_id]->first_name.' '.$guestNames[$record->guest_id]->last_name)
                    : null,
                'guestPhone' => isset($guestNames[$record->guest_id])
                    ? $guestNames[$record->guest_id]->phone : null,
                'roomTypeName' => $record->roomType?->name,
                'assignedRoomNumber' => $record->assignedRoom?->number,
                'roomTypeId' => (string) $record->room_type_id,
                'assignedRoomId' => $record->assigned_room_id !== null ? (string) $record->assigned_room_id : null,
                'checkIn' => $record->check_in->format('Y-m-d'),
                'checkOut' => $record->check_out->format('Y-m-d'),
                'lifecycleState' => $record->lifecycle_state,
                'createdAt' => $record->created_at?->toISOString(),
                'updatedAt' => $record->updated_at?->toISOString(),
                'createdDate' => $record->created_at?->copy()->setTimezone($hotel->timezone ?: 'Asia/Manila')->format('Y-m-d'),
                'earlyCheckIn' => isset($earlyRecords[$record->id]) ? [
                    'scheduledCheckIn' => $earlyRecords[$record->id]->scheduled_check_in,
                    'actualCheckInDate' => $earlyRecords[$record->id]->actual_check_in_date,
                    'actualCheckedInAt' => $earlyRecords[$record->id]->actual_checked_in_at,
                    'approvalRequestId' => (string) $earlyRecords[$record->id]->approval_request_id,
                    'requestedBy' => (string) $earlyRecords[$record->id]->requested_by,
                    'approvedBy' => (string) $earlyRecords[$record->id]->approved_by,
                    'checkedInBy' => (string) $earlyRecords[$record->id]->checked_in_by,
                    'reason' => $earlyRecords[$record->id]->reason,
                    'managerNote' => $earlyRecords[$record->id]->manager_note,
                ] : null,
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

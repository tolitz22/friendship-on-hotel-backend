<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReservationDemandAnalyzer;
use App\Domain\Reservations\StayPeriod;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReservationDemandReportController
{
    private const MAX_RECORDS = 1000;
    private const MAX_NIGHTS = 31;

    public function __invoke(Request $request, Hotel $hotel, ReservationDemandAnalyzer $analyzer): JsonResponse
    {
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from'],
        ]);
        $window = new StayPeriod($input['from'], $input['to']);
        if ($window->nights() > self::MAX_NIGHTS) {
            throw ValidationException::withMessages([
                'to' => ['The reporting window cannot exceed 31 nights.'],
            ]);
        }

        // Load all relevant records, not only assigned ones. Truncation would
        // corrupt every nightly count, so return 422 instead of partial totals.
        $rows = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)
            ->where('check_in', '<', $window->departure)
            ->where('check_out', '>', $window->arrival)
            ->orderBy('id')
            ->limit(self::MAX_RECORDS + 1)
            ->get();

        if ($rows->count() > self::MAX_RECORDS) {
            return response()->json([
                'message' => 'Too many reservation records for this diagnostic window. Narrow the dates.',
                'meta' => ['isAvailability' => false, 'complete' => false],
            ], 422);
        }

        $roomTypes = $hotel->roomTypes()
            ->withCount('rooms')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn ($type): array => [
                'id' => (string) $type->id,
                'name' => $type->name,
                'registeredRooms' => (int) $type->rooms_count,
            ])->all();

        $records = $rows->map(fn (object $row): array => [
            'roomTypeId' => (string) $row->room_type_id,
            'assignedRoomId' => $row->assigned_room_id === null ? null : (string) $row->assigned_room_id,
            'checkIn' => (string) $row->check_in,
            'checkOut' => (string) $row->check_out,
        ])->all();

        return response()->json([
            'data' => [
                'days' => $analyzer->analyze($window, $records, $roomTypes),
                'matchingRecordCount' => count($records),
            ],
            'meta' => [
                'hotelId' => (string) $hotel->id,
                'from' => $window->arrival,
                'to' => $window->departure,
                'timeZone' => $hotel->timezone,
                'complete' => true,
                'isAvailability' => false,
                'isOccupancy' => false,
                'scope' => 'structural_reservation_demand_all_states',
                'warnings' => [
                    'Every lifecycle state is included: inventory-consuming statuses are not approved.',
                    'Registered rooms are not sellable inventory or vacancy.',
                    'No holds, maintenance blocks, overbooking rules or capacity allocator is applied.',
                ],
            ],
        ]);
    }
}

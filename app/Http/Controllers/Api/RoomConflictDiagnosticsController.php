<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\AssignedRoomConflictAnalyzer;
use App\Domain\Reservations\StayPeriod;
use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoomConflictDiagnosticsController
{
    private const MAX_RECORDS = 500;

    /**
     * Structural diagnostics: not an availability, hold, occupancy or booking decision.
     */
    public function __invoke(
        Request $request,
        Hotel $hotel,
        Room $room,
        AssignedRoomConflictAnalyzer $analyzer
    ): JsonResponse {
        if ((string) $room->hotel_id !== (string) $hotel->id) {
            abort(404);
        }

        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from'],
        ]);

        $window = new StayPeriod($input['from'], $input['to']);

        if ($window->nights() > 31) {
            throw ValidationException::withMessages(['to' => ['The diagnostic window cannot exceed 31 nights.']]);
        }

        $query = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)
            ->where('assigned_room_id', $room->id)
            ->where('check_in', '<', $window->departure)
            ->where('check_out', '>', $window->arrival)
            ->orderBy('check_in')
            ->orderBy('id');

        // Never return a truncated set labelled as a complete diagnostic.
        $rows = $query->limit(self::MAX_RECORDS + 1)->get();

        if ($rows->count() > self::MAX_RECORDS) {
            return response()->json([
                'message' => 'Too many structural records. Narrow the date window.',
                'meta' => ['isAvailability' => false, 'complete' => false],
            ], 422);
        }

        $stays = $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'reference' => $row->reference,
            'arrival' => (string) $row->check_in,
            'departure' => (string) $row->check_out,
        ])->all();

        return response()->json([
            'data' => [
                'roomId' => (string) $room->id,
                'records' => array_map(fn (array $stay): array => [
                    'id' => $stay['id'],
                    'reference' => $stay['reference'],
                    'checkIn' => $stay['arrival'],
                    'checkOut' => $stay['departure'],
                ], $stays),
                'overlappingPairs' => $analyzer->pairs($stays),
            ],
            'meta' => [
                'hotelId' => (string) $hotel->id,
                'from' => $window->arrival,
                'to' => $window->departure,
                'timeZone' => $hotel->timezone,
                'isAvailability' => false,
                'complete' => true,
                'scope' => 'assigned_records_only_all_states',
                'excluded' => [
                    'unassigned_reservations',
                    'maintenance_blocks',
                    'inventory_holds',
                    'operational_status_policies',
                ],
            ],
        ]);
    }
}

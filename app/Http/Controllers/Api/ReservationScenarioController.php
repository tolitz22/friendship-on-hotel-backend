<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReservationScenarioAnalyzer;
use App\Domain\Reservations\StayPeriod;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReservationScenarioController
{
    private const MAX_NIGHTS = 31;
    private const MAX_RECORDS = 1000;

    public function __invoke(Request $request, Hotel $hotel, ReservationScenarioAnalyzer $analyzer): JsonResponse
    {
        $input = $request->validate([
            'roomId' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from'],
        ]);

        $room = $hotel->rooms()->findOrFail($input['roomId']);
        $period = new StayPeriod($input['from'], $input['to']);

        if ($period->nights() > self::MAX_NIGHTS) {
            throw ValidationException::withMessages(['to' => ['The simulation is limited to 31 nights.']]);
        }

        $rows = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)
            ->where('room_type_id', $room->room_type_id)
            ->where('check_in', '<', $period->departure)
            ->where('check_out', '>', $period->arrival)
            ->orderBy('check_in')
            ->orderBy('id')
            ->limit(self::MAX_RECORDS + 1)
            ->get();

        if ($rows->count() > self::MAX_RECORDS) {
            return response()->json([
                'message' => 'Too many structural records. Narrow the simulation window.',
                'meta' => ['complete' => false, 'isAvailability' => false],
            ], 422);
        }

        $records = $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'reference' => $row->reference,
            'roomTypeId' => (string) $row->room_type_id,
            'assignedRoomId' => $row->assigned_room_id === null ? null : (string) $row->assigned_room_id,
            'checkIn' => (string) $row->check_in,
            'checkOut' => (string) $row->check_out,
            'lifecycleState' => $row->lifecycle_state,
        ])->all();

        $analysis = $analyzer->analyze($period, (string) $room->room_type_id, (string) $room->id, $records);

        return response()->json([
            'data' => [
                'roomNumber' => $room->number,
                'roomTypeName' => $room->roomType->name,
                ...$analysis,
            ],
            'meta' => [
                'hotelId' => (string) $hotel->id,
                'complete' => true,
                'isSimulation' => true,
                'isAvailability' => false,
                'isBookable' => false,
                'scope' => 'structural_records_all_states',
                'exclusions' => [
                    'approved_inventory_consumption_rules',
                    'holds_and_expirations',
                    'maintenance_and_manual_blocks',
                    'rates_and_payments',
                    'atomic_allocation_and_concurrency',
                ],
                'warning' => 'An empty overlap result DOES NOT mean a room can be booked.',
            ],
        ]);
    }
}

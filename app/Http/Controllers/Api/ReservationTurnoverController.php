<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, hotel-scoped link between an actual checkout and its cleaning
 * task. No mutation, room-readiness assertion, or payment inference.
 */
final class ReservationTurnoverController
{
    public function __invoke(Hotel $hotel, string $booking): JsonResponse
    {
        $record = $hotel->reservationRecords()->whereKey($booking)->firstOrFail();

        $task = DB::table('reservation_turnover_links as l')
            ->join('housekeeping_tasks as t', function ($join): void {
                $join->on('t.id', '=', 'l.housekeeping_task_id')
                    ->on('t.hotel_id', '=', 'l.hotel_id');
            })
            ->where('l.hotel_id', $hotel->id)
            ->where('l.reservation_record_id', $record->id)
            ->where('t.hotel_id', $hotel->id)
            ->orderByDesc('l.id')
            ->first(['t.id', 't.room_id', 't.type', 't.status', 't.revision']);

        return response()->json([
            'data' => [
                'hotelId' => (string) $hotel->id,
                'bookingId' => (string) $record->id,
                'task' => $task === null ? null : [
                    'id' => (string) $task->id,
                    'roomId' => (string) $task->room_id,
                    'type' => $task->type,
                    'status' => $task->status,
                    'revision' => (int) $task->revision,
                ],
                'determinesRoomReadiness' => false,
            ],
        ]);
    }
}

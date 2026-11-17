<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReservationHistoryController
{
    public function __invoke(Request $request, Hotel $hotel, string $booking): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        abort_unless(DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->where('id', $booking)->exists(), 404);

        $page = DB::table('reservation_booking_events')
            ->where('hotel_id', $hotel->id)
            ->where('reservation_record_id', $booking)
            ->orderByDesc('id')
            ->paginate((int) ($input['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (object $event): array => [
                'id' => (string) $event->id,
                'reservationId' => (string) $event->reservation_record_id,
                'eventType' => $event->event_type,
                'oldState' => $event->old_state,
                'newState' => $event->new_state,
                'actorId' => (string) $event->actor_id,
                'createdAt' => $event->created_at,
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'totalPages' => $page->lastPage(),
                'hotelId' => (string) $hotel->id,
                'reservationId' => (string) $booking,
            ],
        ]);
    }
}

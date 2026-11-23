<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class FrontDeskNotificationController
{
    public function __invoke(Hotel $hotel): JsonResponse
    {
        $clock = now($hotel->timezone ?: 'Asia/Manila');
        $today = $clock->toDateString();
        $bookings = DB::table('reservation_records')->where('hotel_id', $hotel->id);
        $departures = (clone $bookings)->where('lifecycle_state', 'checked_in');
        $arrivals = (clone $bookings)->where('lifecycle_state', 'confirmed');
        $overdueToday = $clock->format('H:i') >= FrontDeskReservationController::CHECKOUT_CUTOFF;

        $queues = [
            'checkout_overdue' => (clone $departures)->where('check_out', $overdueToday ? '<=' : '<', $today)
                ->orderBy('check_out')->orderBy('id'),
            'checkout_due' => (clone $departures)->where('check_out', $today)->orderBy('id'),
            'room_assignment' => (clone $arrivals)->where('check_in', $today)
                ->whereNull('assigned_room_id')->orderBy('id'),
            'new_booking' => (clone $bookings)->whereIn('lifecycle_state', ['confirmed', 'checked_in'])
                ->where('created_at', '>=', now()->subDay())->latest('created_at')->orderByDesc('id'),
        ];
        if ($overdueToday) {
            unset($queues['checkout_due']);
        }

        $notifications = [];
        $hasMore = false;
        foreach ($queues as $kind => $query) {
            $rows = $query->limit(9)->get(['id', 'reference', 'check_in', 'check_out']);
            $hasMore = $hasMore || $rows->count() > 8;
            foreach ($rows->take(8) as $booking) {
                $date = str_starts_with($kind, 'checkout_') ? $booking->check_out : $booking->check_in;
                $notifications[] = [
                    'id' => $kind.':'.$booking->id.($kind === 'new_booking' ? '' : ':'.$date),
                    'kind' => $kind,
                    'bookingId' => (string) $booking->id,
                    'reference' => $booking->reference,
                    'date' => $date,
                ];
            }
        }

        return response()->json(['data' => [
            'hotelId' => (string) $hotel->id,
            'notifications' => $notifications,
            'hasMore' => $hasMore,
        ]]);
    }
}

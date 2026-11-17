<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReservationBookingService;
use App\Domain\Reservations\ReservationStayLifecycleService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ReservationBookingController
{
    public function store(Request $request, Hotel $hotel, ReservationBookingService $bookings): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:100'],
            'guestId' => ['required', 'integer', 'min:1'],
            'roomTypeId' => ['required', 'integer', 'min:1'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
        ])->validate();

        $result = $bookings->create($hotel, (int) $request->user()->id, $data);

        return response()->json(
            ['data' => $result],
            $result['idempotentReplay'] ? 200 : 201
        );
    }

    public function checkIn(Request $request, Hotel $hotel, string $booking, ReservationStayLifecycleService $lifecycle): JsonResponse
    {
        return response()->json(['data' => $lifecycle->transition(
            $hotel, (int) $request->user()->id, (int) $booking, 'check_in'
        )]);
    }

    public function checkOut(Request $request, Hotel $hotel, string $booking, ReservationStayLifecycleService $lifecycle): JsonResponse
    {
        return response()->json(['data' => $lifecycle->transition(
            $hotel, (int) $request->user()->id, (int) $booking, 'check_out'
        )]);
    }

    public function cancel(Request $request, Hotel $hotel, string $booking, ReservationBookingService $bookings): JsonResponse
    {
        $result = $bookings->cancel($hotel, (int) $request->user()->id, (int) $booking);

        return response()->json(['data' => $result]);
    }
}

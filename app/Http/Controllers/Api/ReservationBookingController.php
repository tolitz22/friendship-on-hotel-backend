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
            'ratePlanId' => ['sometimes', 'integer', 'min:1'],
            'dynamicQuoteToken' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'corporateCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'prohibits:promoCode'],
            'promoCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'prohibits:corporateCode'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
        ])->validate();

        abort_if((isset($data['corporateCode']) || isset($data['promoCode'])) && ! isset($data['dynamicQuoteToken']), 422, 'Review a dynamic quote before using an eligibility code.');
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
        $input = $request->validate(['confirmedDepartureAt' => ['nullable', 'date_format:Y-m-d\TH:i']]);
        return response()->json(['data' => $lifecycle->transition(
            $hotel, (int) $request->user()->id, (int) $booking, 'check_out',
            confirmedDepartureAt: $input['confirmedDepartureAt'] ?? null
        )]);
    }

    public function noShow(Request $request, Hotel $hotel, string $booking, ReservationBookingService $bookings): JsonResponse
    {
        return response()->json(['data' => $bookings->cancel($hotel, (int) $request->user()->id, (int) $booking, true)]);
    }

    public function cancel(Request $request, Hotel $hotel, string $booking, ReservationBookingService $bookings): JsonResponse
    {
        $result = $bookings->cancel($hotel, (int) $request->user()->id, (int) $booking);

        return response()->json(['data' => $result]);
    }
}

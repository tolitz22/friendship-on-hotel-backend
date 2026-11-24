<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\BookingDynamicQuoteService;
use App\Domain\Reservations\StayPeriod;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BookingDynamicQuoteController
{
    public function quote(Request $request, Hotel $hotel, BookingDynamicQuoteService $service): JsonResponse
    {
        abort_unless(config('booking.dynamic_pricing_enabled', false), 404);
        $input = $request->validate([
            'roomTypeId' => ['required', 'integer', 'min:1'],
            'ratePlanId' => ['required', 'integer', 'min:1'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
            'corporateCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'prohibits:promoCode'],
            'promoCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'prohibits:corporateCode'],
        ]);
        return response()->json(['data' => $service->quote($hotel, (int) $input['roomTypeId'], (int) $input['ratePlanId'],
            new StayPeriod($input['checkIn'], $input['checkOut']), $input['corporateCode'] ?? null, $input['promoCode'] ?? null)]);
    }
}

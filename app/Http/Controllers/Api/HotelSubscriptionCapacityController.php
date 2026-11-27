<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\PlatformSubscriptionCapacity;
use Illuminate\Http\JsonResponse;

final class HotelSubscriptionCapacityController
{
    public function __invoke(Hotel $hotel, PlatformSubscriptionCapacity $capacity): JsonResponse
    {
        return response()->json(['data' => $capacity->snapshot($hotel)])->header('Cache-Control', 'no-store, private');
    }
}

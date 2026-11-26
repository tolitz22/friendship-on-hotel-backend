<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\HotelNotificationService;
use Illuminate\Http\JsonResponse;

final class FrontDeskNotificationController
{
    public function __invoke(Hotel $hotel, HotelNotificationService $notifications): JsonResponse
    {
        return response()->json(['data' => $notifications->feed($hotel)]);
    }
}

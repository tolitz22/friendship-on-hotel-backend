<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\HotelNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FrontDeskNotificationController
{
    public function __invoke(Hotel $hotel, HotelNotificationService $notifications, Request $request): JsonResponse
    {
        $expanded = $request->boolean('expanded') && $request->user()->memberships()
            ->where('hotel_id', $hotel->id)->where('role', 'admin')->where('is_active', true)->exists();

        return response()->json(['data' => $notifications->feed($hotel, $expanded, (int) $request->user()->id)]);
    }
}

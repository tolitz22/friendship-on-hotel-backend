<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotelController
{
    public function index(Request $request): JsonResponse
    {
        $hotels = $request->user()->memberships()
            ->where('is_active', true)
            ->with('hotel:id,name,timezone,currency')
            ->get()
            ->map(fn ($membership): array => [
                'id' => (string) $membership->hotel_id,
                'name' => $membership->hotel->name,
                'timeZone' => $membership->hotel->timezone,
                'currency' => $membership->hotel->currency,
                'role' => $membership->role,
            ])->values();

        return response()->json(['data' => $hotels]);
    }

    public function show(Hotel $hotel): JsonResponse
    {
        return response()->json(['data' => [
            'id' => (string) $hotel->id,
            'name' => $hotel->name,
            'timeZone' => $hotel->timezone,
            'currency' => $hotel->currency,
        ]]);
    }
}

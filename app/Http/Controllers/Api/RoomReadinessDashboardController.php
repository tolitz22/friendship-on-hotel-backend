<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomReadinessDashboardService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoomReadinessDashboardController
{
    public function __invoke(Request $request, Hotel $hotel, RoomReadinessDashboardService $report): JsonResponse
    {
        $input = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'room_page' => ['sometimes', 'integer', 'min:1'],
            'room_per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $membership = $request->user()->memberships()->where('hotel_id', $hotel->id)->where('is_active', true)->firstOrFail();
        $permissions = \App\Http\Middleware\EnsureHotelPermission::effectiveForMembership($membership);
        $canReadArrivals = in_array('reservation.read', $permissions, true) && in_array('guest.read', $permissions, true);
        return response()->json($report->overview(
            $hotel,
            (int) ($input['days'] ?? 7),
            (int) ($input['room_page'] ?? 1),
            (int) ($input['room_per_page'] ?? 20),
            $canReadArrivals,
        ));
    }
}

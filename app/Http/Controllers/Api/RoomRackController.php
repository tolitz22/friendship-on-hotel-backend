<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomRackService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoomRackController
{
    public function __invoke(Request $request, Hotel $hotel, RoomRackService $rack): JsonResponse
    {
        $args = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
        ]);
        $result = $rack->snapshot($hotel,
            (int) ($args['page'] ?? 1), (int) ($args['per_page'] ?? 30),
            (int) ($args['days'] ?? 7));
        $member = $request->user()->memberships()
            ->where('hotel_id', $hotel->id)->where('is_active', true)->first();
        $result['data']['allowedActions'] = [
            'completeInspection' => $member?->role === 'admin'
                && $request->user()->tokenCan('hotel:write'),
        ];
        return response()->json($result);
    }
}

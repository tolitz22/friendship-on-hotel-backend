<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\HousekeepingNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HousekeepingNotificationController
{
    public function __invoke(Request $request, Hotel $hotel, HousekeepingNotifications $notifications): JsonResponse
    {
        $user=$request->user();
        abort_unless($user->is_active && $user->memberships()->where('hotel_id',$hotel->id)
            ->where('role','housekeeping')->where('is_active',true)->exists(),403);
        return response()->json(['data'=>$notifications->personal($hotel,(int)$user->id)]);
    }
}

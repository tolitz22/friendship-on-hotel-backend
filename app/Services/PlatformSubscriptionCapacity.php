<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use App\Models\PlatformHotelSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformSubscriptionCapacity
{
    // All capacity-increasing writers must hold the hotel row lock before
    // calling this method and keep it until their transaction commits.
    public function requireSpace(int $hotelId, string $resource): void
    {
        if (! config('platform.enforce_capacity', true)) {
            return;
        }
        $agreement = PlatformHotelSubscription::where('hotel_id', $hotelId)->first();
        if (! $agreement) {
            return;
        }
        $limit = $resource === 'rooms' ? $agreement->room_limit : $agreement->staff_limit;
        if ($limit === null) {
            return;
        }
        $used = $resource === 'rooms'
            ? DB::table('rooms')->where('hotel_id', $hotelId)->count()
            : DB::table('hotel_memberships')->where('hotel_id', $hotelId)->where('is_active', true)->count();
        if ($used >= $limit) {
            throw ValidationException::withMessages(['subscriptionLimit' => [
                ucfirst($resource).' subscription limit reached ('.$used.'/'.$limit.'). Contact your PMS provider to review your agreement.',
            ]]);
        }
    }

    public function snapshot(Hotel $hotel): array
    {
        $agreement = $hotel->relationLoaded('platformSubscription') ? $hotel->platformSubscription : $hotel->platformSubscription()->first();
        $rooms = isset($hotel->rooms_count) ? (int) $hotel->rooms_count : $hotel->rooms()->count();
        $staff = isset($hotel->capacity_staff_count) ? (int) $hotel->capacity_staff_count : $hotel->memberships()->where('is_active', true)->count();

        return ['enforced' => $agreement !== null && (bool) config('platform.enforce_capacity', true),
            'assigned' => $agreement !== null,
            'rooms' => $this->resource($rooms, $agreement?->room_limit),
            'staff' => $this->resource($staff, $agreement?->staff_limit)];
    }

    private function resource(int $used, ?int $limit): array
    {
        $state = $limit === null ? 'unlimited' : ($used > $limit ? 'over' : ($used === $limit ? 'full' : ($used * 100 >= $limit * 80 ? 'near' : 'available')));

        return ['used' => $used, 'limit' => $limit, 'remaining' => $limit === null ? null : max(0, $limit - $used), 'state' => $state];
    }
}

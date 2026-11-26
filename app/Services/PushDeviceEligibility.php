<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Builder;

final class PushDeviceEligibility
{
    /** Device consent outlives login sessions, but never administrator authority. */
    public function forHotel(int $hotelId): Builder
    {
        return PushSubscription::query()->where('hotel_id', $hotelId)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('hotel_memberships')
                    ->whereColumn('hotel_memberships.hotel_id', 'push_subscriptions.hotel_id')
                    ->whereColumn('hotel_memberships.user_id', 'push_subscriptions.user_id')
                    ->where('hotel_memberships.role', 'admin')->where('hotel_memberships.is_active', true);
            })->whereExists(function ($query): void {
                $query->selectRaw('1')->from('users')->whereColumn('users.id', 'push_subscriptions.user_id')
                    ->where('users.is_active', true)->where('users.must_change_password', false);
            });
    }
}

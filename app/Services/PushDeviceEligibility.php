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
                $query->selectRaw('1')->from('push_endpoint_owners')
                    ->whereColumn('push_endpoint_owners.endpoint_hash', 'push_subscriptions.endpoint_hash')
                    ->whereColumn('push_endpoint_owners.user_id', 'push_subscriptions.user_id');
            })->whereExists(function ($query): void {
                $query->selectRaw('1')->from('hotel_memberships')
                    ->whereColumn('hotel_memberships.hotel_id', 'push_subscriptions.hotel_id')
                    ->whereColumn('hotel_memberships.user_id', 'push_subscriptions.user_id')
                    ->where('hotel_memberships.is_active', true)->where(function ($roles): void {
                        $roles->where('hotel_memberships.role','admin');
                        if (config('push.staff_enabled') === true) {
                            foreach (['housekeeping'=>'housekeeping.assigned','front_desk'=>'reservation.read'] as $role=>$permission) {
                                $roles->orWhere(function ($staff) use ($role,$permission): void {
                                    $staff->where('hotel_memberships.role',$role)->where(function ($allowed) use ($permission): void {
                                        $allowed->whereNull('hotel_memberships.permission_overrides')->orWhereJsonContains('hotel_memberships.permission_overrides',$permission);
                                    });
                                });
                            }
                        }
                    });
            })->whereExists(function ($query): void {
                $query->selectRaw('1')->from('users')->whereColumn('users.id', 'push_subscriptions.user_id')
                    ->where('users.is_active', true)->where('users.must_change_password', false);
            });
    }
}

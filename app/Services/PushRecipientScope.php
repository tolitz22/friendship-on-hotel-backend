<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Http\Middleware\EnsureHotelPermission;

final class PushRecipientScope
{
    public function role(Hotel $hotel,int $userId): ?string
    {
        $member=HotelMembership::query()->where('hotel_id',$hotel->id)->where('user_id',$userId)
            ->where('is_active',true)->whereHas('user',fn ($q)=>$q->where('is_active',true)->where('must_change_password',false))->first();
        if (!$member) return null;
        if ($member->role==='admin') return 'admin';
        if (config('push.staff_enabled')!==true) return null;
        $permission=match($member->role){'housekeeping'=>'housekeeping.assigned','front_desk'=>'reservation.read',default=>null};
        return $permission!==null&&in_array($permission,EnsureHotelPermission::effectiveForMembership($member),true)?$member->role:null;
    }

    public function current(Hotel $hotel,int $userId,string $kind,string $id): ?array
    {
        $role=$this->role($hotel,$userId);
        if ($role==='admin') return in_array($kind,StaffMessageNotifications::KINDS,true)
            ?app(StaffMessageNotifications::class)->current($hotel,$userId,$id)
            :app(HotelNotificationService::class)->current($hotel,$id);
        if ($role==='front_desk'&&$kind==='room_inspection_approved') return app(OperationalActivityNotifications::class)->current($hotel,$id);
        if ($role==='housekeeping'&&in_array($kind,['hk_assigned','hk_arrival_urgent','hk_overdue'],true)) {
            foreach (app(HousekeepingNotifications::class)->personal($hotel,$userId,null)['notifications'] as $alert) {
                if ($alert['id']===$id&&$alert['kind']===$kind) return $alert;
            }
        }
        return null;
    }
}

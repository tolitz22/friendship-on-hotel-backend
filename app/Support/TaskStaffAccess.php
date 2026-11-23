<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskStaffAccess
{
    public static function isAdmin(Request $request, Hotel $hotel): bool
    {
        return $request->user()->tokenCan('hotel:write')
            && $request->user()->memberships()
                ->where('hotel_id',$hotel->id)->where('role','admin')
                ->where('is_active',true)->exists();
    }

    public static function verifyAssignee(Hotel $hotel, ?int $userId, string $role): void
    {
        if ($userId===null) return;
        $valid=DB::table('hotel_memberships')->join('users','users.id','=','hotel_memberships.user_id')
            ->where('hotel_memberships.hotel_id',$hotel->id)
            ->where('hotel_memberships.user_id',$userId)
            ->where('hotel_memberships.is_active',true)
            ->where('users.is_active',true)
            ->whereIn('hotel_memberships.role',[$role,'admin'])->exists();
        if (!$valid) throw ValidationException::withMessages([
            'assignedUserId'=>['Assign an active '.str_replace('_',' ',$role).' member of the selected hotel.'],
        ]);
    }

    public static function canOperate(Request $request, Hotel $hotel, object $task, string $role): bool
    {
        if (self::isAdmin($request,$hotel)) return true;
        if ((string)($task->assigned_user_id??'') !== (string)$request->user()->id) return false;
        $membership=$request->user()->memberships()
            ->where('hotel_id',$hotel->id)->where('role',$role)
            ->where('is_active',true)->first();
        return $membership !== null
            && in_array($role.'.assigned',
                \App\Http\Middleware\EnsureHotelPermission::effectiveForMembership($membership),true);
    }
}

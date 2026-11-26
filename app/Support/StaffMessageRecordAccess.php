<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\EnsureHotelPermission;
use App\Models\Hotel;
use App\Models\HotelMembership;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StaffMessageRecordAccess
{
    public const TYPES = ['room', 'booking', 'housekeeping', 'maintenance'];

    public function query(Request $request, Hotel $hotel, string $type): ?Builder
    {
        $user = $request->user();
        if (!$user || !$user->is_active || !$user->tokenCan('hotel:read')) return null;
        $membership = HotelMembership::where('hotel_id', $hotel->id)
            ->where('user_id', $user->id)->where('is_active', true)->first();
        if (!$membership) return null;
        $permissions = EnsureHotelPermission::effectiveForMembership($membership);
        $role = $membership->role;
        if (!in_array($role, ['admin','front_desk','housekeeping','maintenance'], true)) return null;
        $reservationRead = in_array('reservation.read', $permissions, true);
        $table = match ($type) {
            'room' => $role !== 'front_desk' || $reservationRead ? 'rooms' : null,
            'booking' => $reservationRead ? 'reservation_records' : null,
            'housekeeping' => in_array($role, ['admin','housekeeping','maintenance'], true) ? 'housekeeping_tasks' : null,
            'maintenance' => in_array($role, ['admin','housekeeping','maintenance'], true) ? 'maintenance_work_orders' : null,
            default => null,
        };
        return $table === null ? null : DB::table($table)->where('hotel_id', $hotel->id);
    }

    public function columns(string $type): array
    {
        return match ($type) {
            'room' => ['id','number'],
            'booking' => ['id','reference'],
            'housekeeping','maintenance' => ['id'],
            default => ['id'],
        };
    }

    public function present(string $type, object $record): array
    {
        $id = (string) $record->id;
        return [
            'type' => $type,
            'id' => $id,
            'label' => match ($type) {
                'room' => 'Room '.$record->number,
                'booking' => 'Booking '.$record->reference,
                'housekeeping' => 'Housekeeping task #'.$id,
                'maintenance' => 'Maintenance work order #'.$id,
            },
            'target' => $type === 'room' ? (string) $record->number : $id,
        ];
    }

    public function resolve(Request $request, Hotel $hotel, string $type, string $id): ?array
    {
        $row = $this->query($request, $hotel, $type)?->where('id', $id)->first($this->columns($type));
        return $row === null ? null : $this->present($type, $row);
    }
}

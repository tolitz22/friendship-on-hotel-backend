<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PlatformHotelAccess
{
    public static function state(Hotel $hotel): string
    {
        return $hotel->platform_access_state ?? 'active';
    }

    public static function requireWritable(Hotel $hotel): void
    {
        if (self::state($hotel) === 'suspended') {
            throw new HttpException(423, 'Hotel operations are suspended. Existing records remain available for authorized viewing. Contact your PMS provider.');
        }
    }

    public static function requireNewBooking(Hotel $hotel): void
    {
        self::requireWritable($hotel);
        if (self::state($hotel) === 'restricted') {
            throw new HttpException(423, 'New bookings are restricted for this hotel. Existing guest stays and settlement can continue.');
        }
    }

    // For critical writers: call inside a transaction, before subordinate locks.
    public static function lock(int $hotelId): Hotel
    {
        return Hotel::query()->lockForUpdate()->findOrFail($hotelId);
    }

    public static function blockers(int $hotelId): array
    {
        return [
            'openReservations' => DB::table('reservation_records')->where('hotel_id', $hotelId)
                ->where(fn ($q) => $q->whereNull('lifecycle_state')->orWhereNotIn('lifecycle_state', ['cancelled', 'checked_out', 'no_show']))->count(),
            'activeHolds' => DB::table('group_allotments')->where('hotel_id', $hotelId)->where('status', 'held')->where('expires_at', '>', now())->count(),
            'unreconciledShifts' => DB::table('cashier_shifts')->where('hotel_id', $hotelId)->where('status', '!=', 'reconciled')->count(),
        ];
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Logging\SecurityLog;
use Illuminate\Http\Request;
use Closure;
use Symfony\Component\HttpFoundation\Response;

class EnsureHotelMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $hotel = $request->route('hotel');

        if (! $request->user()->tokenCan('hotel:read')) {
            abort(403, 'A hotel read token is required.');
        }

        // Never let users enumerate or read a property they do not belong to.
        if (! $hotel instanceof Hotel || ! $request->user()->memberships()
            ->where('hotel_id', $hotel->getKey())
            ->where('is_active', true)->exists()) {
            SecurityLog::record('pms.security.access.membership_denied', ['actor_id' => (int) $request->user()->id, 'status_code' => 404], true);
            abort(404);
        }

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            \App\Services\PlatformHotelAccess::requireWritable($hotel);
            if ($request->isMethod('POST') && in_array($request->route()->uri(), [
                'api/v1/hotels/{hotel}/bookings', 'api/v1/hotels/{hotel}/group-reservations',
                'api/v1/hotels/{hotel}/group-allotments',
            ], true)) {
                \App\Services\PlatformHotelAccess::requireNewBooking($hotel);
            }
        }

        return $next($request);
    }
}

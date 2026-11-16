<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
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
            abort(404);
        }

        return $next($request);
    }
}

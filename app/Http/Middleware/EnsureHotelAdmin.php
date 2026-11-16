<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHotelAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $hotel = $request->route('hotel');

        if (! $hotel instanceof Hotel) {
            abort(404);
        }

        if (! $request->user()->tokenCan('hotel:write') ||
            ! $request->user()->memberships()
                ->where('hotel_id', $hotel->id)
                ->where('role', 'admin')
                ->where('is_active', true)->exists()) {
            abort(403, 'An active hotel administrator is required.');
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Hotel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorize a specific action against the CURRENT hotel membership, not a
 * frontend role label or a role cached when a Sanctum token was issued.
 */
final class EnsureHotelPermission
{
    private const ROLE_PERMISSIONS = [
        'admin' => ['booking.create', 'booking.check_in', 'booking.check_out', 'booking.assign_room', 'guest.read', 'reservation.read', 'finance.read', 'approval.read', 'approval.request', 'cashier.read', 'cashier.write'],
        'front_desk' => ['booking.create', 'booking.check_in', 'booking.check_out', 'booking.assign_room', 'guest.read', 'reservation.read', 'finance.read', 'approval.read', 'approval.request'],
        'housekeeping' => [],
        'maintenance' => [],
    ];

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $hotel = $request->route('hotel');
        if (! $hotel instanceof Hotel) {
            abort(404);
        }

        $user = $request->user();
        if (! $user || ! $user->is_active || ! $user->tokenCan('hotel:read')) {
            abort(403);
        }

        $membership = $user->memberships()
            ->where('hotel_id', $hotel->getKey())
            ->where('is_active', true)->first();

        if ($membership === null) {
            abort(404);
        }

        $allowed = in_array($permission, self::ROLE_PERMISSIONS[$membership->role] ?? [], true);

        // Admin tokens require the legacy write ability. Receptionist tokens
        // require an explicitly issued booking capability (never read alone).
        $tokenAllowed = (str_starts_with($permission, 'booking.') || $permission === 'approval.request' || $permission === 'cashier.write')
            ? ($membership->role === 'admin' ? $user->tokenCan('hotel:write') : $user->tokenCan('booking:write'))
            : $user->tokenCan('hotel:read');
        if (! $allowed || ! $tokenAllowed) {
            abort(403, 'Your hotel role does not permit this operation.');
        }

        return $next($request);
    }

    public static function forRole(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }
}

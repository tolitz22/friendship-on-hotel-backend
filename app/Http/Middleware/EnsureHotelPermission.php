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
        'admin' => ['reimbursement.read_own', 'reimbursement.submit', 'reimbursement.read_all', 'reimbursement.review', 'reimbursement.pay', 'supplier.read', 'supplier.manage', 'supplier.bill.post', 'supplier.bill.pay', 'expense.read', 'expense.record', 'expense.record_direct', 'expense.review', 'expense.policy', 'booking.create', 'booking.check_in', 'booking.check_out', 'booking.assign_room', 'booking.special_request', 'guest.read', 'reservation.read', 'finance.read', 'approval.read', 'approval.request', 'cashier.read', 'cashier.write', 'keycard.read', 'keycard.request', 'occupancy.read'],
        'front_desk' => ['reimbursement.read_own', 'reimbursement.submit', 'expense.record', 'booking.create', 'booking.check_in', 'booking.check_out', 'booking.assign_room', 'booking.special_request', 'guest.read', 'reservation.read', 'finance.read', 'approval.read', 'approval.request', 'cashier.read', 'cashier.write', 'keycard.read', 'keycard.request'],
        'housekeeping' => ['reimbursement.read_own', 'reimbursement.submit', 'occupancy.read', 'occupancy.observe', 'housekeeping.assigned'],
        'maintenance' => ['reimbursement.read_own', 'reimbursement.submit', 'maintenance.assigned'],
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

        $allowed = in_array($permission, self::effectiveForMembership($membership), true);

        // Admin tokens require the legacy write ability. Receptionist tokens
        // require an explicitly issued booking capability (never read alone).
        $tokenAllowed = (str_starts_with($permission, 'booking.') || $permission === 'approval.request' || $permission === 'cashier.write' || $permission === 'keycard.request' || in_array($permission, ['expense.record', 'expense.record_direct', 'expense.review', 'expense.policy', 'supplier.manage', 'supplier.bill.post', 'supplier.bill.pay', 'reimbursement.review', 'reimbursement.pay'], true))
            ? ($membership->role === 'admin' ? $user->tokenCan('hotel:write') : $user->tokenCan('booking:write'))
            : $user->tokenCan('hotel:read');
        if ($permission === 'reimbursement.submit') {
            $tokenAllowed = $user->tokenCan('reimbursement:submit');
        }
        if (! $allowed || ! $tokenAllowed) {
            abort(403, 'Your hotel role does not permit this operation.');
        }

        return $next($request);
    }

    /**
     * Staff overrides are a subset of the role template, never a way to
     * become a manager. NULL inherits the template; [] revokes every listed
     * delegated capability. Permissions are read from the current membership
     * for every API request, including long-lived sessions.
     */
    public static function effectiveForMembership(\App\Models\HotelMembership $membership): array
    {
        $base = self::forRole($membership->role);
        if ($membership->role === 'admin') return $base;
        $overrides = $membership->permission_overrides;
        if ($overrides === null) return $base;
        return array_values(array_intersect($base, $overrides));
    }

    public static function forRole(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }
}

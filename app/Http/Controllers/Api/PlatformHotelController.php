<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\SecurityLog;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class PlatformHotelController
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        $search = trim($input['search'] ?? '');
        $query = Hotel::query()->select(['id', 'name', 'timezone', 'currency', 'created_at'])
            ->withCount(['rooms', 'memberships as active_staff_count' => fn ($q) => $q->where('is_active', true)->whereHas('user', fn ($u) => $u->where('is_active', true)),
                'memberships as active_admin_count' => fn ($q) => $q->where('is_active', true)->where('role', 'admin')->whereHas('user', fn ($u) => $u->where('is_active', true))]);
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
        }
        $page = $query->orderBy('id', 'desc')->paginate(20);
        SecurityLog::record('pms.security.platform.hotels_viewed', ['actor_id' => (int) $request->user()->id]);

        return response()->json(['data' => $page->getCollection()->map(static fn (Hotel $hotel): array => [
            'id' => (string) $hotel->id, 'name' => $hotel->name, 'timezone' => $hotel->timezone,
            'currency' => $hotel->currency, 'createdAt' => $hotel->created_at?->toIso8601String(),
            'roomCount' => (int) $hotel->rooms_count, 'activeStaffCount' => (int) $hotel->active_staff_count,
            'activeAdminCount' => (int) $hotel->active_admin_count,
        ])->values(), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        // Initial commercial scope: Philippine properties only. No plan, payment,
        // trial or access suspension is implied by creating a property.
        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'adminName' => ['required', 'string', 'min:2', 'max:120'],
            'adminEmail' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);
        $email = mb_strtolower(trim($input['adminEmail']));
        if (strlen($input['password']) > 72) {
            throw ValidationException::withMessages(['password' => ['Use a password of at most 72 bytes.']]);
        }
        try {
            [$hotel, $admin] = DB::transaction(function () use ($input, $email): array {
                if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['adminEmail' => ['This email already has an account. Use a new administrator email.']]);
                }
                $hotel = Hotel::create(['name' => trim($input['name']), 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
                $admin = User::create(['name' => trim($input['adminName']), 'email' => $email, 'password' => $input['password'],
                    'is_active' => true, 'must_change_password' => true]);
                HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);

                return [$hotel, $admin];
            }, 3);
        } catch (QueryException $error) {
            // The unique email constraint also protects simultaneous requests.
            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['adminEmail' => ['This email already has an account. Use a new administrator email.']]);
            }
            throw $error;
        }
        SecurityLog::record('pms.security.platform.hotel_created', ['actor_id' => (int) $request->user()->id,
            'hotel_id' => (int) $hotel->id, 'target_user_id' => (int) $admin->id]);

        return response()->json(['data' => ['id' => (string) $hotel->id, 'name' => $hotel->name, 'adminId' => (string) $admin->id,
            'passwordChangeRequired' => true]], 201)->header('Cache-Control', 'no-store, private');
    }
}

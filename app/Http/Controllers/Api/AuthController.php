<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Logging\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function login(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ])->validate();

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            SecurityLog::record('pms.security.login.failed', ['auth_mode' => 'bearer'], true);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $abilities = ['hotel:read'];
        if ($user->memberships()->where('is_active', true)->whereIn('role', ['admin', 'front_desk', 'housekeeping', 'maintenance'])->exists()) {
            $abilities[] = 'reimbursement:submit';
        }

        if ($user->memberships()->where('role', 'admin')->where('is_active', true)->exists()) {
            $abilities[] = 'hotel:write';
        }

        if ($user->memberships()->where('role', 'front_desk')->where('is_active', true)->exists()) {
            $abilities[] = 'booking:write';
        }

        $token = $user->createToken('hotel-pms', $abilities);
        SecurityLog::record('pms.security.login.succeeded', ['user_id' => (int) $user->id, 'auth_mode' => 'bearer']);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'tokenType' => 'Bearer',
                'user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mustChangePassword' => (bool) $user->must_change_password,
            'platformAdmin' => \App\Support\PlatformAccess::allowed($user),
            'hotels' => $user->memberships()->where('is_active', true)
                ->with('hotel:id,name,platform_access_state')->get()
                ->map(fn ($membership): array => [
                    'id' => (string) $membership->hotel_id,
                    'name' => $membership->hotel->name,
                    'accessState' => \App\Services\PlatformHotelAccess::state($membership->hotel),
                    'role' => $membership->role,
                    'permissions' => \App\Http\Middleware\EnsureHotelPermission::effectiveForMembership($membership),
                ])->values(),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $request->user()->currentAccessToken()?->delete();
        SecurityLog::record('pms.security.logout.succeeded', ['user_id' => $userId, 'auth_mode' => 'bearer']);

        return response()->json(['message' => 'Signed out.']);
    }
}

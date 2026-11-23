<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
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
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $abilities = ['hotel:read'];

        if ($user->memberships()->where('role', 'admin')->where('is_active', true)->exists()) {
            $abilities[] = 'hotel:write';
        }

        if ($user->memberships()->where('role', 'front_desk')->where('is_active', true)->exists()) {
            $abilities[] = 'booking:write';
        }

        $token = $user->createToken('hotel-pms', $abilities);

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
            'hotels' => $user->memberships()->where('is_active', true)
                ->with('hotel:id,name')->get()
                ->map(fn ($membership): array => [
                    'id' => (string) $membership->hotel_id,
                    'name' => $membership->hotel->name,
                    'role' => $membership->role,
                    'permissions' => \App\Http\Middleware\EnsureHotelPermission::effectiveForMembership($membership),
                ])->values(),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}

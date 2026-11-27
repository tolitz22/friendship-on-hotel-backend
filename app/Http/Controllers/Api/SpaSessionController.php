<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Logging\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class SpaSessionController
{
    private function ensureEnabled(Request $request): void
    {
        // Reject direct/token-based attempts to create a browser session.
        // Sanctum's stateful middleware adds the Laravel session and CSRF checks
        // ONLY for recognized first-party SPA origins.
        abort_unless(config('spa_auth.enabled') && $request->hasSession(), 403);
    }

    public function login(Request $request): JsonResponse
    {
        $this->ensureEnabled($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            SecurityLog::record('pms.security.login.failed', ['auth_mode' => 'cookie'], true);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        SecurityLog::record('pms.security.login.succeeded', ['user_id' => (int) $user->id, 'auth_mode' => 'cookie']);

        return response()->json([
            'data' => [
                'authenticated' => true,
                'authMode' => 'cookie',
                'user' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->ensureEnabled($request);
        abort_unless(Auth::guard('web')->check(), 401);

        $userId = (int) Auth::guard('web')->id();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        SecurityLog::record('pms.security.logout.succeeded', ['user_id' => $userId, 'auth_mode' => 'cookie']);

        return response()->json(['message' => 'Signed out.']);
    }
}

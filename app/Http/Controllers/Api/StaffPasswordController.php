<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

final class StaffPasswordController
{
    private function passwordRules(): array
    {
        return ['required','confirmed',PasswordRule::min(12)->mixedCase()->numbers()];
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required','email','max:255']]);
        // Do not reveal whether an account exists, whether it is active, or whether
        // the broker has throttled its mail. No token is returned to the browser.
        $email = strtolower(trim($data['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user !== null && $user->is_active) {
            try {
                Password::broker('users')->sendResetLink(['email' => $user->email]);
            } catch (\\Throwable $error) {
                // Do not reveal account existence through email-delivery errors.
                report($error);
            }
        }

        return response()->json([
            'message' => 'If an active staff account exists for that email, password reset instructions will be sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required','email','max:255'],
            'token' => ['required','string'],
            'password' => $this->passwordRules(),
        ]);

        $status = Password::broker('users')->reset($data, function (User $user, string $password): void {
            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    'token' => ['The password reset request could not be completed.'],
                ]);
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            // Password reset invalidates all existing Sanctum sessions.
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['The reset link is invalid or expired. Request a new link.'],
            ]);
        }

        return response()->json([
            'message' => 'Password reset. Sign in with your new password.',
        ]);
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required','string'],
            'password' => $this->passwordRules(),
        ]);

        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($data['currentPassword'], $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Choose a password different from your current password.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
        ])->save();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password changed. Sign in again using the new password.',
        ]);
    }
}

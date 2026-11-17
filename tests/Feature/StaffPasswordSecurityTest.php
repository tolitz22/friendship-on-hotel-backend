<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\StaffPasswordResetNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;

it('returns the same forgot-password response for known and unknown emails', function (): void {
    Notification::fake();
    $user = User::create([
        'name'=>'Staff Member','email'=>'staff-recovery@example.test',
        'password'=>'ExistingPass123!',
    ]);

    $known=$this->postJson('/api/v1/auth/forgot-password',['email'=>$user->email])
        ->assertOk()->json('message');
    $unknown=$this->postJson('/api/v1/auth/forgot-password',['email'=>'missing@example.test'])
        ->assertOk()->json('message');

    expect($known)->toBe($unknown);
    Notification::assertSentTo($user,StaffPasswordResetNotification::class);
});

it('rejects malformed and expired password reset tokens', function (): void {
    $user=User::create([
        'name'=>'Token Tester','email'=>'invalid-reset@example.test',
        'password'=>'ExistingPass123!',
    ]);

    $this->postJson('/api/v1/auth/reset-password',[
        'email'=>$user->email,
        'token'=>'invalid-token',
        'password'=>'NewPassword123!',
        'password_confirmation'=>'NewPassword123!',
    ])->assertUnprocessable()->assertJsonValidationErrors(['token']);

    expect(Hash::check('ExistingPass123!',$user->fresh()->password))->toBeTrue();
});

it('resets a valid password and revokes every existing bearer token', function (): void {
    $user=User::create([
        'name'=>'Reset Tester','email'=>'valid-reset@example.test',
        'password'=>'ExistingPass123!',
    ]);
    $user->createToken('one',['hotel:read']);
    $user->createToken('two',['hotel:read']);
    $token=Password::broker('users')->createToken($user);

    $this->postJson('/api/v1/auth/reset-password',[
        'email'=>$user->email,
        'token'=>$token,
        'password'=>'BrandNewPass123!',
        'password_confirmation'=>'BrandNewPass123!',
    ])->assertOk();

    expect(Hash::check('BrandNewPass123!',$user->fresh()->password))->toBeTrue();
    expect(PersonalAccessToken::where('tokenable_id',$user->id)->count())->toBe(0);
    $this->postJson('/api/v1/auth/login',[
        'email'=>$user->email,'password'=>'ExistingPass123!',
    ])->assertUnprocessable();
    $this->postJson('/api/v1/auth/login',[
        'email'=>$user->email,'password'=>'BrandNewPass123!',
    ])->assertOk();
});

it('does not reset inactive accounts even when a valid token exists', function (): void {
    $user=User::create([
        'name'=>'Inactive Staff','email'=>'inactive-reset@example.test',
        'password'=>'ExistingPass123!','is_active'=>false,
    ]);
    $token=Password::broker('users')->createToken($user);

    $this->postJson('/api/v1/auth/reset-password',[
        'email'=>$user->email,
        'token'=>$token,
        'password'=>'BrandNewPass123!',
        'password_confirmation'=>'BrandNewPass123!',
    ])->assertUnprocessable();
    expect(Hash::check('ExistingPass123!',$user->fresh()->password))->toBeTrue();
});

it('requires current password and confirmation for an authenticated password change', function (): void {
    $user=User::create([
        'name'=>'Change Tester','email'=>'change-password@example.test',
        'password'=>'ExistingPass123!',
    ]);
    $token=$user->createToken('staff',['hotel:read'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/change-password',[
        'currentPassword'=>'wrong',
        'password'=>'BrandNewPass123!',
        'password_confirmation'=>'BrandNewPass123!',
    ])->assertUnprocessable()->assertJsonValidationErrors(['currentPassword']);

    $this->withToken($token)->postJson('/api/v1/auth/change-password',[
        'currentPassword'=>'ExistingPass123!',
        'password'=>'short',
        'password_confirmation'=>'short',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

    $this->withToken($token)->postJson('/api/v1/auth/change-password',[
        'currentPassword'=>'ExistingPass123!',
        'password'=>'BrandNewPass123!',
        'password_confirmation'=>'different',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

    expect(PersonalAccessToken::where('tokenable_id',$user->id)->count())->toBe(1);
});

it('changes the password and invalidates all active sessions', function (): void {
    $user=User::create([
        'name'=>'Security Tester','email'=>'password-change@example.test',
        'password'=>'ExistingPass123!',
    ]);
    $token=$user->createToken('staff',['hotel:read'])->plainTextToken;
    $user->createToken('second-session',['hotel:read']);

    $this->withToken($token)->postJson('/api/v1/auth/change-password',[
        'currentPassword'=>'ExistingPass123!',
        'password'=>'BrandNewPass123!',
        'password_confirmation'=>'BrandNewPass123!',
    ])->assertOk();

    expect(Hash::check('BrandNewPass123!',$user->fresh()->password))->toBeTrue();
    expect(PersonalAccessToken::where('tokenable_id',$user->id)->count())->toBe(0);
});

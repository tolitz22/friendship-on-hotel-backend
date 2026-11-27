<?php

use App\Logging\ConfigureStructuredLogging;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

it('logs authentication and password events without credentials or email addresses', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'pms-security-');
    try {
        config(['logging.default' => 'security_test', 'logging.channels.security_test' => [
            'driver' => 'single', 'path' => $path, 'level' => 'info',
            'tap' => [ConfigureStructuredLogging::class],
        ]]);
        Notification::fake();
        $user = User::create(['name' => 'Synthetic Staff', 'email' => 'security-test@example.test', 'password' => 'ExistingPass123!']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnprocessable();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'ExistingPass123!'])->assertOk();
        $this->withToken($login->json('data.token'))->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token,
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertOk();

        Log::forgetChannel('security_test');
        $raw = file_get_contents($path);
        $records = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $security = array_values(array_filter($records, fn ($r) => str_starts_with($r['message'], 'pms.security.')));
        expect(array_column($security, 'message'))->toBe([
            'pms.security.login.failed', 'pms.security.login.succeeded', 'pms.security.logout.succeeded',
            'pms.security.password.reset_requested', 'pms.security.password.reset_succeeded',
        ])->and($security[0]['context'])->not->toHaveKey('user_id')
            ->and($security[0]['level_name'])->toBe('WARNING')
            ->and($security[1]['context']['request_id'])->toBe($login->headers->get('X-Request-ID'))
            ->and($security[1]['context']['user_id'])->toBe($user->id)
            ->and($raw)->not->toContain($user->email, $token, 'wrong-password', 'ExistingPass123!', 'NewPassword123!', $login->json('data.token'));
    } finally {
        Log::forgetChannel('security_test');
        @unlink($path);
    }
});

it('logs actual staff permission updates, not no-ops, and identifies access denials', function (): void {
    $hotel = Hotel::create(['name' => 'Synthetic Hotel']);
    $admin = User::create(['name' => 'Admin', 'email' => 'log-admin@example.test', 'password' => 'ExistingPass123!']);
    $worker = User::create(['name' => 'Worker', 'email' => 'log-worker@example.test', 'password' => 'ExistingPass123!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $worker->id, 'role' => 'housekeeping', 'is_active' => true]);
    Log::spy();
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $url = '/api/v1/hotels/'.$hotel->id.'/staff/'.$worker->id;
    $this->patchJson($url, ['role' => 'maintenance'])->assertOk();
    $this->patchJson($url, ['role' => 'maintenance'])->assertOk();
    Log::shouldHaveReceived('log')->with('info', 'pms.security.staff.updated', [
        'actor_id' => (int) $admin->id, 'target_user_id' => (int) $worker->id, 'hotel_id' => (int) $hotel->id,
    ])->once();
    Sanctum::actingAs($worker, ['hotel:read']);
    $this->patchJson($url, ['role' => 'admin'])->assertForbidden();
    Log::shouldHaveReceived('log')->with('warning', 'pms.security.access.denied', ['status_code' => 403])->once();
});

<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

function spaTestUser(): User
{
    return User::create([
        'name' => 'Browser Admin',
        'email' => 'browser-'.uniqid().'@example.test',
        'password' => 'CorrectPassword123!',
    ]);
}

function enableSpaSessionForTest(): void
{
    config()->set('spa_auth.enabled', true);
    config()->set('sanctum.stateful', ['localhost:5173']);
    config()->set('cors.supports_credentials', true);
}

it('rejects SPA session login when the feature is disabled', function (): void {
    // Keep this security test independent of each developer's .env settings.
    config()->set('spa_auth.enabled', false);
    $user=spaTestUser();

    $this->withHeaders([
        'Origin'=>'http://localhost:5173',
        'Referer'=>'http://localhost:5173/',
    ])->postJson('/api/v1/auth/spa-login',[
        'email'=>$user->email,'password'=>'CorrectPassword123!',
    ])->assertForbidden();

    expect(PersonalAccessToken::count())->toBe(0);
});

it('uses the web guard for first-party session login without issuing a personal access token', function (): void {
    enableSpaSessionForTest();
    $user=spaTestUser();
    $hotel=Hotel::create(['name'=>'Browser Property']);
    HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$user->id,
        'role'=>'admin','is_active'=>true,
    ]);

    $this->withHeaders([
        'Origin'=>'http://localhost:5173',
        'Referer'=>'http://localhost:5173/',
    ])->postJson('/api/v1/auth/spa-login',[
        'email'=>$user->email,'password'=>'CorrectPassword123!',
    ])->assertOk()
        ->assertJsonPath('data.authMode','cookie')
        ->assertJsonPath('data.authenticated',true)
        ->assertJsonMissingPath('data.token');

    expect(PersonalAccessToken::count())->toBe(0);
    expect(Auth::guard('web')->check())->toBeTrue();

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id',(string)$user->id);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/staff')->assertOk();
});

it('rejects bad or disabled staff credentials in cookie login', function (): void {
    enableSpaSessionForTest();
    $active=spaTestUser();
    $disabled=User::create([
        'name'=>'Inactive Browser User',
        'email'=>'inactive-'.uniqid().'@example.test',
        'password'=>'CorrectPassword123!','is_active'=>false,
    ]);

    $request=$this->withHeaders([
        'Origin'=>'http://localhost:5173',
        'Referer'=>'http://localhost:5173/',
    ]);
    $request->postJson('/api/v1/auth/spa-login',[
        'email'=>$active->email,'password'=>'wrong',
    ])->assertUnprocessable();
    $request->postJson('/api/v1/auth/spa-login',[
        'email'=>$disabled->email,'password'=>'CorrectPassword123!',
    ])->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
});

it('does not create SPA login sessions for untrusted request origins', function (): void {
    enableSpaSessionForTest();
    $user=spaTestUser();

    $this->withHeaders([
        'Origin'=>'https://untrusted.example',
        'Referer'=>'https://untrusted.example/',
    ])->postJson('/api/v1/auth/spa-login',[
        'email'=>$user->email,'password'=>'CorrectPassword123!',
    ])->assertForbidden();

    expect(PersonalAccessToken::count())->toBe(0);
});

it('keeps the old token login available when SPA session mode is disabled', function (): void {
    config()->set('spa_auth.enabled', false);
    $user=spaTestUser();

    $login=$this->postJson('/api/v1/auth/login',[
        'email'=>$user->email,'password'=>'CorrectPassword123!',
    ])->assertOk()->assertJsonPath('data.tokenType','Bearer');

    $this->withToken($login->json('data.token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id',(string)$user->id);
});

it('refuses SPA logout without an established first-party browser session', function (): void {
    enableSpaSessionForTest();
    $this->withHeaders([
        'Origin'=>'https://untrusted.example',
        'Referer'=>'https://untrusted.example/',
    ])->postJson('/api/v1/auth/spa-logout')->assertUnauthorized();
});

it('logs out a first-party cookie session without touching API token authentication', function (): void {
    enableSpaSessionForTest();
    $user=spaTestUser();

    $this->withHeaders([
        'Origin'=>'http://localhost:5173',
        'Referer'=>'http://localhost:5173/',
    ])->postJson('/api/v1/auth/spa-login',[
        'email'=>$user->email,'password'=>'CorrectPassword123!',
    ])->assertOk();

    $this->postJson('/api/v1/auth/spa-logout')->assertOk()
        ->assertJsonPath('message','Signed out.');

    expect(Auth::guard('web')->check())->toBeFalse();
    expect(PersonalAccessToken::count())->toBe(0);
    // Sanctum's RequestGuard may cache the previous test request's user.
    // Force the next request to authenticate again against the invalidated session.
    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

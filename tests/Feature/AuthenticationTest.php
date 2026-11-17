<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

it('rejects bad credentials', function (): void {
    User::create(['name' => 'Demo Admin', 'email' => 'admin@example.test', 'password' => 'a-long-password']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    expect(PersonalAccessToken::count())->toBe(0);
});

it('issues a token and revokes it at logout', function (): void {
    User::create(['name' => 'Demo Admin', 'email' => 'admin@example.test', 'password' => 'a-long-password']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'a-long-password',
    ])->assertOk()->assertJsonPath('data.tokenType', 'Bearer');

    $token = $response->json('data.token');
    expect($token)->toBeString();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'admin@example.test');
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect(PersonalAccessToken::count())->toBe(0);
});

it('denies access to a disabled staff account', function (): void {
    User::create(['name' => 'Disabled', 'email' => 'disabled@example.test', 'password' => 'a-long-password', 'is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'disabled@example.test',
        'password' => 'a-long-password',
    ])->assertUnprocessable();
});


it('lists only active hotel memberships for frontend property selection', function (): void {
    $user = User::create([
        'name' => 'Hotel Staff',
        'email' => 'staff-context@example.test',
        'password' => 'secure-test-password',
    ]);
    $active = \App\Models\Hotel::create(['name' => 'Friendship On Hotel']);
    $inactive = \App\Models\Hotel::create(['name' => 'Inactive Membership Hotel']);
    \App\Models\HotelMembership::create([
        'hotel_id' => $active->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'is_active' => true,
    ]);
    \App\Models\HotelMembership::create([
        'hotel_id' => $inactive->id,
        'user_id' => $user->id,
        'role' => 'front_desk',
        'is_active' => false,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'staff-context@example.test',
        'password' => 'secure-test-password',
    ])->assertOk();

    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonCount(1, 'data.hotels')
        ->assertJsonPath('data.hotels.0.id', (string) $active->id)
        ->assertJsonPath('data.hotels.0.name', 'Friendship On Hotel')
        ->assertJsonPath('data.hotels.0.role', 'admin');
});

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

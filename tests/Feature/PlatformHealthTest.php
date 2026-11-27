<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('denies anonymous and property administrator infrastructure access', function (): void {
    Http::fake();
    $this->getJson('/api/v1/platform/health')->assertUnauthorized();
    $user = User::create(['name' => 'Hotel admin', 'email' => 'health-hotel@example.test', 'password' => 'synthetic-password']);
    $hotel = Hotel::create(['name' => 'Synthetic hotel']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    config(['platform.admin_ids' => [(string) $user->id]]);
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/platform/health')->assertForbidden();
    Http::assertNothingSent();
});

it('checks infrastructure independently and never exposes credentials or remote logs', function (): void {
    $user = User::create(['name' => 'Operator', 'email' => 'health@example.test', 'password' => 'synthetic-password']);
    Sanctum::actingAs($user);
    config(['platform.admin_ids' => [(string) $user->id], 'platform.deployment_revision' => 'abcdef1234567', 'cache.default' => 'array',
        'platform.monitoring' => ['url' => 'https://demo.grafana.net', 'datasource_uid' => 'loki-uid', 'token' => 'read-secret', 'service_label' => 'service_name']]);
    Cache::put('unrelated-key', 'keep', 60);
    Http::fake(['https://demo.grafana.net/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [['values' => [['123', 'private raw log']]]]]])]);
    $this->getJson('/api/v1/platform/health')->assertOk()
        ->assertJsonPath('data.backend.state', 'healthy')->assertJsonPath('data.cache.scope', 'local')
        ->assertJsonPath('data.cache.state', 'degraded')->assertJsonPath('data.state', 'degraded')
        ->assertJsonPath('data.grafana.state', 'healthy')->assertJsonPath('data.deployment.revision', 'abcdef1234567')
        ->assertDontSee('read-secret')->assertDontSee('private raw log');
    expect(Cache::get('unrelated-key'))->toBe('keep');
    Http::assertSentCount(1);
    config(['platform.monitoring.token' => '']);
    $this->getJson('/api/v1/platform/health')->assertOk()->assertJsonPath('data.grafana.state', 'not_configured');
    Http::assertSentCount(1);
});

it('reports provider failure without confusing it with backend unavailability', function (): void {
    $user = User::create(['name' => 'Operator', 'email' => 'health-failure@example.test', 'password' => 'synthetic-password']);
    Sanctum::actingAs($user);
    config(['platform.admin_ids' => [(string) $user->id], 'platform.deployment_revision' => 'private invalid value',
        'platform.monitoring' => ['url' => 'https://demo.grafana.net', 'datasource_uid' => 'loki-uid', 'token' => 'read-secret', 'service_label' => 'service_name']]);
    Http::fake(['https://demo.grafana.net/*' => Http::response('private upstream error', 503)]);
    $this->getJson('/api/v1/platform/health')->assertOk()->assertJsonPath('data.backend.state', 'healthy')
        ->assertJsonPath('data.grafana.state', 'unavailable')->assertJsonPath('data.state', 'degraded')
        ->assertJsonPath('data.deployment.revision', null)->assertDontSee('private upstream error');
});

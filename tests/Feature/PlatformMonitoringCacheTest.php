<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::create(['name' => 'Operator', 'email' => 'monitor-cache@example.test', 'password' => 'synthetic-password']);
    Sanctum::actingAs($user);
    config(['cache.default' => 'array', 'platform.admin_ids' => [(string) $user->id], 'platform.monitoring' => [
        'url' => 'https://demo.grafana.net', 'datasource_uid' => 'loki-uid', 'token' => 'read-secret', 'service_label' => 'service_name',
    ]]);
});

it('reuses successful identical queries, separates filters and preserves authorization', function (): void {
    Http::fake(['https://demo.grafana.net/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]])]);
    $first = $this->getJson('/api/v1/platform/monitoring')->assertOk()->json('data');
    $this->getJson('/api/v1/platform/monitoring?minutes=60&role=all&environment=all')->assertOk()->assertJsonPath('data.observedAt', $first['observedAt']);
    Http::assertSentCount(1);
    $this->getJson('/api/v1/platform/monitoring?role=worker')->assertOk();
    Http::assertSentCount(2);
    config(['platform.admin_ids' => []]);
    $this->getJson('/api/v1/platform/monitoring')->assertForbidden();
    Http::assertSentCount(2);
});

it('does not cache failed upstream reads or reuse old credentials', function (): void {
    Http::fake(['https://demo.grafana.net/*' => Http::sequence()
        ->push('private provider error', 503)
        ->push(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]])
        ->push(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]])]);
    $this->getJson('/api/v1/platform/monitoring')->assertOk()->assertJsonPath('data.state', 'unavailable');
    $this->getJson('/api/v1/platform/monitoring')->assertOk()->assertJsonPath('data.state', 'ready');
    $this->getJson('/api/v1/platform/monitoring')->assertOk()->assertJsonPath('data.state', 'ready');
    Http::assertSentCount(2);
    config(['platform.monitoring.token' => 'rotated-token']);
    $this->getJson('/api/v1/platform/monitoring')->assertOk();
    Http::assertSentCount(3);
});

it('allows twenty identical metric requests but still limits excess calls', function (): void {
    Http::fake(function ($request) {
        $type = str_contains($request->url(), 'query_range') ? 'matrix' : 'vector';
        return Http::response(['status' => 'success', 'data' => ['resultType' => $type, 'result' => []]]);
    });
    for ($i = 0; $i < 20; $i++) {
        $this->getJson('/api/v1/platform/monitoring/metrics')->assertOk()->assertJsonPath('data.state', 'ready');
    }
    $this->getJson('/api/v1/platform/monitoring/metrics')->assertStatus(429)->assertHeader('Retry-After');
    Http::assertSentCount(3);
});

it('expires cached results after thirty seconds without caching partial responses', function (): void {
    $cache = app(\App\Logging\PlatformMonitoringCache::class);
    $calls = 0;
    $load = function () use (&$calls): array {
        return ['state' => ++$calls === 1 ? 'partial' : 'ready', 'calls' => $calls];
    };
    $filters = [60, 'all', 'all', null];
    expect($cache->read('metrics', $filters, $load)['calls'])->toBe(1);
    expect($cache->read('metrics', $filters, $load)['calls'])->toBe(2);
    expect($cache->read('metrics', $filters, $load)['calls'])->toBe(2);
    $this->travel(31)->seconds();
    expect($cache->read('metrics', $filters, $load)['calls'])->toBe(3);
});

<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

function authorizeMonitoringMetrics(): void
{
    $user = User::create(['name' => 'Operator', 'email' => 'metrics@example.test', 'password' => 'synthetic-password']);
    Sanctum::actingAs($user);
    config(['platform.admin_ids' => [(string) $user->id], 'platform.monitoring' => [
        'url' => 'https://demo.grafana.net', 'datasource_uid' => 'loki-uid', 'token' => 'read-secret', 'service_label' => 'service_name',
    ]]);
}

it('aggregates exported records beyond the event list limit with fixed queries', function (): void {
    authorizeMonitoringMetrics();
    Http::fake(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);
        $query = $params['query'];
        if (str_contains($query, 'quantile_over_time')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1, '123.45']]]]]);
        }
        if (str_contains($request->url(), 'query_range')) {
            $end = (int) substr($params['end'], 0, -9);
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => ['pms_level' => 'ERROR'], 'values' => [[$end, '12']]],
            ]]]);
        }
        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['pms_event' => 'pms.api.request.completed', 'pms_level' => 'INFO', 'pms_status' => '200'], 'value' => [1, '2500']],
            ['metric' => ['pms_event' => 'pms.api.request.completed', 'pms_level' => 'ERROR', 'pms_status' => '503'], 'value' => [1, '12']],
            ['metric' => ['pms_event' => 'pms.frontend.error_reported', 'pms_level' => 'WARNING', 'pms_status' => '500'], 'value' => [1, '4']],
            ['metric' => ['pms_event' => 'pms.security.login.failed', 'pms_level' => 'WARNING'], 'value' => [1, '3']],
        ]]]);
    });
    $this->getJson('/api/v1/platform/monitoring/metrics?minutes=15&role=backend&environment=staging')
        ->assertOk()->assertJsonPath('data.state', 'ready')->assertJsonPath('data.counts.requests', 2512)
        ->assertJsonPath('data.counts.serverErrors', 12)->assertJsonPath('data.counts.errors', 12)
        ->assertJsonPath('data.counts.frontendErrors', 4)->assertJsonPath('data.counts.authFailures', 3)
        ->assertJsonPath('data.p95', 123.5)->assertJsonCount(12, 'data.trend')
        ->assertJsonPath('data.trend.11.errors', 12)->assertDontSee('read-secret');
    Http::assertSentCount(3);
    foreach (Http::recorded() as [$request, $response]) {
        expect(urldecode($request->url()))->toContain('pms_role="backend"', 'pms_env="staging"');
        expect($request->hasHeader('Authorization', 'Bearer read-secret'))->toBeTrue();
    }
});

it('distinguishes failed metrics from successful empty queries and blocks unauthorized requests', function (): void {
    Http::preventStrayRequests();
    $this->getJson('/api/v1/platform/monitoring/metrics')->assertUnauthorized();
    authorizeMonitoringMetrics();
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'query_range')) {
            return Http::response('private upstream error', 503);
        }
        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
    });
    $this->getJson('/api/v1/platform/monitoring/metrics')->assertOk()
        ->assertJsonPath('data.state', 'partial')->assertJsonPath('data.counts.requests', 0)
        ->assertJsonPath('data.latencyState', 'no_data')->assertJsonPath('data.p95', null)
        ->assertJsonPath('data.trend', null)->assertDontSee('private upstream error');
    $this->getJson('/api/v1/platform/monitoring/metrics?role=%22%7D')->assertUnprocessable();
    config(['platform.admin_ids' => []]);
    $this->getJson('/api/v1/platform/monitoring/metrics')->assertForbidden();
    Http::assertSentCount(3);
});

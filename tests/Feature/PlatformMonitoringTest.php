<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('denies anonymous and hotel admins even if a hotel admin ID is mistakenly allowlisted', function (): void {
    Http::fake();
    $this->getJson('/api/v1/platform/monitoring')->assertUnauthorized();
    $user = User::create(['name' => 'Hotel Admin', 'email' => 'hotel@example.test', 'password' => 'synthetic-password']);
    $hotel = Hotel::create(['name' => 'Test']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    config(['platform.admin_ids' => [(string) $user->id]]);
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.platformAdmin', false);
    $this->getJson('/api/v1/platform/monitoring')->assertForbidden();
    Http::assertNothingSent();
});

it('projects only safe fields for a dedicated platform account and validates filters', function (): void {
    $user = User::create(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'synthetic-password']);
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/platform/monitoring')->assertForbidden();
    config(['platform.admin_ids' => [(string) $user->id]]);
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.platformAdmin', true)->assertJsonCount(0, 'data.hotels');
    $this->getJson('/api/v1/platform/monitoring')->assertOk()->assertJsonPath('data.state', 'not_configured');
    config(['platform.monitoring' => ['url' => 'https://demo.grafana.net', 'datasource_uid' => 'loki-uid', 'token' => 'read-secret', 'service_label' => 'service_name']]);
    $line = json_encode(['message' => 'pms.api.request.completed', 'level_name' => 'ERROR',
        'context' => ['status_code' => 500, 'duration_ms' => 123, 'password' => 'private-password'],
        'extra' => ['service' => 'friendship-on-hotel-backend', 'schema_version' => 1, 'environment' => 'staging', 'service_role' => 'backend']]);
    Http::fake(['https://demo.grafana.net/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams',
        'result' => [['values' => [['1795780000000000000', $line], ['1795780000000000000', 'private raw line']]]]]], 200)]);
    $this->getJson('/api/v1/platform/monitoring?role=backend&environment=staging&minutes=15')
        ->assertOk()->assertJsonPath('data.state', 'ready')->assertJsonPath('data.sampleSize', 1)
        ->assertJsonPath('data.events.0.statusCode', 500)->assertDontSee('private-password')->assertDontSee('read-secret')->assertDontSee('private raw line');
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer read-secret') && str_contains(urldecode($r->url()), 'extra_service_role="backend"'));
    $this->getJson('/api/v1/platform/monitoring?requestId=%22%7D')->assertUnprocessable();
    config(['platform.admin_ids' => []]);
    $this->getJson('/api/v1/platform/monitoring')->assertForbidden();
    Http::assertSentCount(1);
});

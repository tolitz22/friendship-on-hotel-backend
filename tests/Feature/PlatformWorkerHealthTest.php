<?php

use App\Logging\PlatformHeartbeat;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('restricts worker diagnostics to dedicated platform admins', function (): void {
    $this->getJson('/api/v1/platform/workers')->assertUnauthorized();
    $user = User::create(['name' => 'Hotel admin', 'email' => 'worker-hotel@example.test', 'password' => 'synthetic-password']);
    $hotel = Hotel::create(['name' => 'Synthetic hotel']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    config(['platform.admin_ids' => [(string) $user->id]]);
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/platform/workers')->assertForbidden();
});

it('keeps fixed heartbeat rows and distinguishes stale, missing and disabled work', function (): void {
    $operator = User::create(['name' => 'Operator', 'email' => 'worker-op@example.test', 'password' => 'synthetic-password']);
    Sanctum::actingAs($operator);
    config(['platform.admin_ids' => [(string) $operator->id], 'logging.service_role' => 'worker', 'push.enabled' => false,
        'queue.default' => 'sync', 'queue.failed.driver' => 'null']);
    $heartbeat = app(PlatformHeartbeat::class);
    $heartbeat->record('scheduler', 'observed');
    $heartbeat->record('scheduler', 'observed');
    $this->artisan('pms:push-deliver')->assertSuccessful();
    $this->getJson('/api/v1/platform/workers')->assertOk()
        ->assertJsonPath('data.heartbeats.scheduler.state', 'recent')
        ->assertJsonPath('data.heartbeats.queue_worker.state', 'never_seen')
        ->assertJsonPath('data.heartbeats.push_completed.outcome', 'disabled')
        ->assertJsonPath('data.queue.state', 'not_applicable')
        ->assertJsonPath('data.queue.failed24h', null);
    expect(DB::table('platform_heartbeats')->count())->toBe(3);
    event(new Looping('database', 'default'));
    $first = DB::table('platform_heartbeats')->where('name', 'queue_worker')->value('observed_at');
    $this->travel(10)->seconds();
    event(new Looping('database', 'default'));
    expect((int) DB::table('platform_heartbeats')->where('name', 'queue_worker')->value('observed_at'))->toBe((int) $first);
    $this->travel(4)->minutes();
    $this->getJson('/api/v1/platform/workers')->assertOk()->assertJsonPath('data.heartbeats.scheduler.state', 'stale');
    event(new Looping('database', 'default'));
    expect(DB::table('platform_heartbeats')->count())->toBe(4);
    $this->getJson('/api/v1/platform/workers')->assertOk()->assertJsonPath('data.heartbeats.queue_worker.state', 'recent');
});

it('reports push backlog and leases without exposing device or notification details', function (): void {
    $operator = User::create(['name' => 'Operator', 'email' => 'worker-counts@example.test', 'password' => 'synthetic-password']);
    Sanctum::actingAs($operator);
    config(['platform.admin_ids' => [(string) $operator->id]]);
    $hotel = Hotel::create(['name' => 'Synthetic hotel']);
    $device = PushSubscription::create(['hotel_id' => $hotel->id, 'user_id' => $operator->id,
        'endpoint_hash' => str_repeat('a', 64), 'endpoint' => 'https://fcm.googleapis.com/send/private-endpoint',
        'p256dh' => 'qa', 'auth' => 'qa', 'device_name' => 'Synthetic']);
    foreach ([['pending', -120], ['pending', 120], ['processing', -60], ['sent', 0], ['failed', 0]] as $i => [$status, $offset]) {
        DB::table('push_deliveries')->insert(['push_subscription_id' => $device->id, 'notification_id' => 'private-event-'.$i,
            'kind' => 'new_booking', 'status' => $status, 'available_at' => now()->addSeconds($offset),
            'created_at' => now(), 'updated_at' => now()]);
    }
    $this->getJson('/api/v1/platform/workers')->assertOk()
        ->assertJsonPath('data.push.ready', 1)->assertJsonPath('data.push.delayed', 1)
        ->assertJsonPath('data.push.processing', 1)->assertJsonPath('data.push.expiredLeases', 1)
        ->assertJsonPath('data.push.sent24h', 1)->assertJsonPath('data.push.failed24h', 1)
        ->assertDontSee('private-endpoint')->assertDontSee('private-event');
    expect(DB::table('push_deliveries')->count())->toBe(5);
});

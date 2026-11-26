<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('registers admin devices idempotently and isolates subscriptions', function (): void {
    $hotel = Hotel::create(['name' => 'Push Hotel']);
    $other = Hotel::create(['name' => 'Other Push Hotel']);
    $admin = User::create(['name' => 'Admin', 'email' => 'push-admin@example.test', 'password' => 'long-passphrase']);
    $desk = User::create(['name' => 'Desk', 'email' => 'push-desk@example.test', 'password' => 'long-passphrase']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $desk->id, 'role' => 'front_desk']);
    $url = '/api/v1/hotels/'.$hotel->id.'/push-subscriptions';
    $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/example', 'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)], 'deviceName' => 'Desktop'];
    $this->postJson($url, $payload)->assertUnauthorized();
    Sanctum::actingAs($desk, ['hotel:read']);
    $this->postJson($url, $payload)->assertForbidden();
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $this->postJson('/api/v1/hotels/'.$other->id.'/push-subscriptions', $payload)->assertNotFound();
    $id = $this->postJson($url, $payload)->assertOk()->json('data.id');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.endpoint');
    $this->postJson($url, array_replace($payload, ['endpoint' => 'https://127.0.0.1/private']))->assertUnprocessable();
    $this->deleteJson($url.'/'.$id)->assertOk();
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
});

it('keeps push preference polling separate from subscription rate limits', function (): void {
    $hotel = Hotel::create(['name' => 'Push throttle QA']);
    $admin = User::create(['name' => 'Admin', 'email' => 'push-throttle@example.test', 'password' => 'long-passphrase', 'is_active' => true, 'must_change_password' => false]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $url = '/api/v1/hotels/'.$hotel->id;
    for ($i = 0; $i < 30; $i++) {
        $this->getJson($url.'/push-preferences')->assertOk();
    }
    $this->getJson($url.'/push-preferences')->assertStatus(429);
    $this->getJson($url.'/push-subscriptions')->assertOk();
});

it('reserves each endpoint for one account across properties even after device removal', function (): void {
    $hotel = Hotel::create(['name' => 'Owner QA']);
    $other = Hotel::create(['name' => 'Other owner QA']);
    $a = User::create(['name' => 'Owner A', 'email' => 'owner-a@example.test', 'password' => 'long-passphrase']);
    $b = User::create(['name' => 'Owner B', 'email' => 'owner-b@example.test', 'password' => 'long-passphrase']);
    foreach ([$hotel, $other] as $property) {
        foreach ([$a, $b] as $user) {
            HotelMembership::create(['hotel_id' => $property->id, 'user_id' => $user->id, 'role' => 'admin']);
        }
    }
    $url = '/api/v1/hotels/'.$hotel->id.'/push-subscriptions';
    $otherUrl = '/api/v1/hotels/'.$other->id.'/push-subscriptions';
    $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/owner-qa', 'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)], 'deviceName' => 'Trusted device'];
    Sanctum::actingAs($a, ['hotel:read', 'hotel:write']);
    $id = $this->postJson($url, $payload)->assertOk()->json('data.id');
    $otherId = $this->postJson($otherUrl, $payload)->assertOk()->json('data.id');
    Sanctum::actingAs($b, ['hotel:read', 'hotel:write']);
    $this->postJson($otherUrl, $payload)->assertUnprocessable();
    $this->deleteJson($url.'/'.$id)->assertNotFound();
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    Sanctum::actingAs($a, ['hotel:read', 'hotel:write']);
    $this->deleteJson($url.'/'.$id)->assertOk();
    $this->deleteJson($otherUrl.'/'.$otherId)->assertOk();
    Sanctum::actingAs($b, ['hotel:read', 'hotel:write']);
    $this->postJson($url, $payload)->assertUnprocessable();
    expect(\Illuminate\Support\Facades\DB::table('push_endpoint_owners')->count())->toBe(1);
});

it('rejects untrusted endpoints and avoids returning subscription secrets', function (): void {
    $hotel = Hotel::create(['name' => 'Endpoint QA']);
    $admin = User::create(['name' => 'Admin', 'email' => 'endpoint-qa@example.test', 'password' => 'long-passphrase']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin']);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $url = '/api/v1/hotels/'.$hotel->id.'/push-subscriptions';
    $payload = ['keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)], 'deviceName' => 'QA'];
    foreach (['http://fcm.googleapis.com/send/a', 'https://fcm.googleapis.com.evil.test/send/a', 'https://fcm.googleapis.com:8443/send/a', 'https://user@fcm.googleapis.com/send/a', 'https://fcm.googleapis.com/send/a#fragment'] as $endpoint) {
        $this->postJson($url, $payload + ['endpoint' => $endpoint])->assertUnprocessable();
    }
    $this->postJson($url, $payload + ['endpoint' => 'https://fcm.googleapis.com/send/valid'])->assertOk()
        ->assertJsonMissingPath('data.endpoint')->assertJsonMissingPath('data.p256dh')->assertJsonMissingPath('data.auth')->assertJsonMissingPath('data.endpoint_hash');
});

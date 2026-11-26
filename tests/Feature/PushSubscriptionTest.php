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

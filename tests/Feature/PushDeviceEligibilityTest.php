<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushDeviceEligibility;
use Laravel\Sanctum\Sanctum;

it('keeps opted-in admin devices eligible after token logout but excludes revoked authority', function (): void {
    $hotel = Hotel::create(['name' => 'Push Pilot']);
    $other = Hotel::create(['name' => 'Other Hotel']);
    $user = User::create(['name' => 'Admin', 'email' => 'push-eligibility@example.test', 'password' => 'long-passphrase']);
    $membership = HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin']);
    $device = PushSubscription::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'endpoint_hash' => str_repeat('a', 64), 'endpoint' => 'https://fcm.googleapis.com/fcm/send/test', 'p256dh' => 'test', 'auth' => 'test', 'device_name' => 'Personal phone']);
    $token = $user->createToken('hotel-pms', ['hotel:read', 'hotel:write']);
    $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/logout')->assertOk();
    expect($user->tokens()->count())->toBe(0);
    $service = app(PushDeviceEligibility::class);
    expect($service->forHotel($hotel->id)->whereKey($device->id)->exists())->toBeTrue();
    expect($service->forHotel($other->id)->exists())->toBeFalse();
    $membership->update(['role' => 'front_desk']);
    expect($service->forHotel($hotel->id)->exists())->toBeFalse();
    $membership->update(['role' => 'admin']);
    $user->update(['is_active' => false]);
    expect($service->forHotel($hotel->id)->exists())->toBeFalse();
});

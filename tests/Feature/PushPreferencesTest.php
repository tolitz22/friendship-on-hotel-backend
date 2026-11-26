<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use App\Services\PushPreferences;
use Laravel\Sanctum\Sanctum;

it('persists administrator preferences by property and enforces category opt outs', function (): void {
    $admin = User::create(['name' => 'Admin', 'email' => 'preferences@example.test', 'password' => 'long-passphrase']);
    $hotel = Hotel::create(['name' => 'Preference Hotel']);
    $other = Hotel::create(['name' => 'Other Hotel']);
    foreach ([$hotel, $other] as $property) {
        HotelMembership::create(['hotel_id' => $property->id, 'user_id' => $admin->id, 'role' => 'admin']);
    }
    $url = '/api/v1/hotels/'.$hotel->id.'/push-preferences';
    $this->getJson($url)->assertUnauthorized();
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.enabled', true);
    $this->putJson($url, ['enabled' => false])->assertUnprocessable();
    $values = ['enabled' => true, 'new_booking' => false, 'checkout_due' => true, 'checkout_overdue' => true, 'room_assignment' => true];
    $this->putJson($url, $values)->assertOk()->assertJsonPath('data.new_booking', false);
    $this->getJson('/api/v1/hotels/'.$other->id.'/push-preferences')->assertOk()->assertJsonPath('data.new_booking', true);
    $preferences = app(PushPreferences::class);
    expect($preferences->allows($hotel->id, $admin->id, 'new_booking'))->toBeFalse();
    expect($preferences->allows($hotel->id, $admin->id, 'checkout_due'))->toBeTrue();
    $values['enabled'] = false;
    $this->putJson($url, $values)->assertOk();
    expect($preferences->allows($hotel->id, $admin->id, 'checkout_due'))->toBeFalse();
    HotelMembership::where('hotel_id', $hotel->id)->where('user_id', $admin->id)->update(['role' => 'front_desk']);
    $this->getJson($url)->assertForbidden();
});

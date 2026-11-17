<?php

declare(strict_types=1);

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function signInForGuestTests(Hotel $hotel, string $role = 'front_desk', array $abilities = ['hotel:read']): User
{
    $user = User::create([
        'name' => 'Guest Test Staff',
        'email' => 'guest-staff-'.uniqid().'@example.test',
        'password' => 'sample-test-password',
    ]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => $role]);
    Sanctum::actingAs($user, $abilities);

    return $user;
}

it('requires authentication and isolates guest profiles by hotel', function (): void {
    $a = Hotel::create(['name' => 'Hotel A']);
    $b = Hotel::create(['name' => 'Hotel B']);
    $aGuest = $a->guests()->create(['first_name' => 'Alice', 'last_name' => 'Santos']);
    $bGuest = $b->guests()->create(['first_name' => 'Bob', 'last_name' => 'Reyes']);

    $this->getJson('/api/v1/hotels/'.$a->id.'/guests')->assertUnauthorized();

    signInForGuestTests($a);
    $this->getJson('/api/v1/hotels/'.$a->id.'/guests')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $aGuest->id)
        ->assertJsonPath('data.0.firstName', 'Alice')
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/hotels/'.$b->id.'/guests')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$a->id.'/guests/'.$bGuest->id)->assertNotFound();
});

it('supports literal search and pagination without cross-hotel leaks', function (): void {
    $hotel = Hotel::create(['name' => 'Search Hotel']);
    $hotel->guests()->create(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'email' => 'maria@example.test']);
    $hotel->guests()->create(['first_name' => 'Jana', 'last_name' => 'Santos', 'email' => 'jana@example.test']);
    $hotel->guests()->create(['first_name' => 'Anna', 'last_name' => 'Tan', 'phone' => '09123456789']);
    signInForGuestTests($hotel);

    $url = '/api/v1/hotels/'.$hotel->id.'/guests';
    $this->getJson($url.'?search=MARIA')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lastName', 'Dela Cruz');
    $this->getJson($url.'?search=Maria%20Dela%20Cruz')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.firstName', 'Maria');
    $this->getJson($url.'?search=maria%20dela%20cruz')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lastName', 'Dela Cruz');
    $this->getJson($url.'?search=%25')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url.'?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.totalPages', 3);

    $this->getJson($url.'?per_page=101')->assertUnprocessable();
});

it('allows hotel administrators to create and partially update guests', function (): void {
    $hotel = Hotel::create(['name' => 'Reception Hotel']);
    signInForGuestTests($hotel, 'admin', ['hotel:read', 'hotel:write']);

    $url = '/api/v1/hotels/'.$hotel->id.'/guests';
    $guest = $this->postJson($url, [
        'firstName' => '  Juan  ',
        'lastName' => '  Dela Cruz ',
        'email' => 'juan@example.test',
        'phone' => '09123456789',
        'nationality' => 'Filipino',
    ])->assertCreated()
        ->assertJsonPath('data.firstName', 'Juan')
        ->assertJsonPath('data.hotelId', (string) $hotel->id)
        ->json('data');

    $this->patchJson($url.'/'.$guest['id'], [
        'phone' => null,
        'lastName' => 'Santos',
    ])->assertOk()->assertJsonPath('data.lastName', 'Santos')
        ->assertJsonPath('data.phone', null)
        ->assertJsonPath('data.email', 'juan@example.test');

    expect(Guest::findOrFail($guest['id'])->first_name)->toBe('Juan');
});

it('rejects invalid guest data and prevents cross-hotel updates', function (): void {
    $hotel = Hotel::create(['name' => 'Own Hotel']);
    $other = Hotel::create(['name' => 'Other Hotel']);
    $otherGuest = $other->guests()->create(['first_name' => 'Other', 'last_name' => 'Guest']);
    signInForGuestTests($hotel, 'admin', ['hotel:read', 'hotel:write']);

    $url = '/api/v1/hotels/'.$hotel->id.'/guests';
    $this->postJson($url, ['firstName' => '  ', 'lastName' => 'Santos'])->assertUnprocessable();
    $this->postJson($url, ['firstName' => 'John', 'lastName' => 'Santos', 'email' => 'invalid-email'])
        ->assertUnprocessable();
    $this->patchJson($url.'/'.$otherGuest->id, ['lastName' => 'Changed'])->assertNotFound();

    expect($otherGuest->fresh()->last_name)->toBe('Guest');
});

it('denies guest writes to non-admins and tokens without write capability', function (): void {
    $hotel = Hotel::create(['name' => 'Restricted Hotel']);
    $url = '/api/v1/hotels/'.$hotel->id.'/guests';
    signInForGuestTests($hotel, 'front_desk', ['hotel:read']);
    $this->postJson($url, ['firstName' => 'Jane', 'lastName' => 'Doe'])->assertForbidden();

    signInForGuestTests($hotel, 'admin', ['hotel:read']);
    $this->postJson($url, ['firstName' => 'Jane', 'lastName' => 'Doe'])->assertForbidden();
    expect($hotel->guests()->count())->toBe(0);
});

it('has no guest deletion endpoint', function (): void {
    $hotel = Hotel::create(['name' => 'No Delete Hotel']);
    $guest = $hotel->guests()->create(['first_name' => 'Keep', 'last_name' => 'Guest']);
    signInForGuestTests($hotel, 'admin', ['hotel:read', 'hotel:write']);

    $this->deleteJson('/api/v1/hotels/'.$hotel->id.'/guests/'.$guest->id)->assertStatus(405);
    expect($hotel->guests()->count())->toBe(1);
});

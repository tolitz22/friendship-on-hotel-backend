<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function catalogAdminLogin(Hotel $hotel, string $role = 'admin', array $abilities = ['hotel:read', 'hotel:write']): void
{
    $staff = User::create([
        'name' => 'Catalogue Administrator',
        'email' => 'catalog-create-'.uniqid().'@example.test',
        'password' => 'example-password',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id,
        'user_id' => $staff->id,
        'role' => $role,
        'is_active' => true,
    ]);
    Sanctum::actingAs($staff, $abilities);
}

it('creates a room type and physical room only within the selected hotel', function (): void {
    $hotel = Hotel::create(['name' => 'Creation Property']);
    catalogAdminLogin($hotel);

    $type = $this->postJson('/api/v1/hotels/'.$hotel->id.'/room-types', [
        'name' => 'Deluxe',
        'maxOccupancy' => 3,
    ])->assertCreated()->assertJsonPath('data.name', 'Deluxe')->json('data');

    $this->postJson('/api/v1/hotels/'.$hotel->id.'/rooms', [
        'roomTypeId' => $type['id'],
        'number' => '401',
        'floor' => 4,
    ])->assertCreated()
        ->assertJsonPath('data.number', '401')
        ->assertJsonPath('data.roomTypeName', 'Deluxe')
        ->assertJsonPath('data.hotelId', (string) $hotel->id);

    $this->getJson('/api/v1/hotels/'.$hotel->id.'/catalog-summary')
        ->assertOk()
        ->assertJsonPath('data.totalRooms', 1)
        ->assertJsonPath('data.totalRoomTypes', 1);
});

it('rejects invalid room input and duplicate catalogue entries', function (): void {
    $hotel = Hotel::create(['name' => 'Validation Property']);
    catalogAdminLogin($hotel);
    $url = '/api/v1/hotels/'.$hotel->id;

    $this->postJson($url.'/room-types', ['name' => 'Standard', 'maxOccupancy' => 0])->assertUnprocessable();

    $type = $this->postJson($url.'/room-types', [
        'name' => 'Standard', 'maxOccupancy' => 2,
    ])->assertCreated()->json('data');

    $this->postJson($url.'/room-types', [
        'name' => 'Standard', 'maxOccupancy' => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->postJson($url.'/rooms', [
        'roomTypeId' => $type['id'], 'number' => '101', 'floor' => -1,
    ])->assertUnprocessable();

    $this->postJson($url.'/rooms', [
        'roomTypeId' => $type['id'], 'number' => '101', 'floor' => 1,
    ])->assertCreated();

    $this->postJson($url.'/rooms', [
        'roomTypeId' => $type['id'], 'number' => '101', 'floor' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors(['number']);

    expect(Room::where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('blocks rooms assigned to a room type from another property', function (): void {
    $hotel = Hotel::create(['name' => 'Property A']);
    $other = Hotel::create(['name' => 'Property B']);
    $foreignType = RoomType::create([
        'hotel_id' => $other->id, 'name' => 'Suite', 'max_occupancy' => 4,
    ]);
    catalogAdminLogin($hotel);

    $this->postJson('/api/v1/hotels/'.$hotel->id.'/rooms', [
        'roomTypeId' => (string) $foreignType->id,
        'number' => '501',
    ])->assertUnprocessable()->assertJsonValidationErrors(['roomTypeId']);
    expect($hotel->rooms()->count())->toBe(0);
});

it('restricts catalogue writes to active hotel admins with a write-capable token', function (): void {
    $hotel = Hotel::create(['name' => 'Restricted Catalogue']);
    $url = '/api/v1/hotels/'.$hotel->id.'/room-types';

    $this->postJson($url, ['name' => 'Suite', 'maxOccupancy' => 2])->assertUnauthorized();

    catalogAdminLogin($hotel, 'front_desk', ['hotel:read', 'hotel:write']);
    $this->postJson($url, ['name' => 'Suite', 'maxOccupancy' => 2])->assertForbidden();

    catalogAdminLogin($hotel, 'admin', ['hotel:read']);
    $this->postJson($url, ['name' => 'Suite', 'maxOccupancy' => 2])->assertForbidden();
    expect($hotel->roomTypes()->count())->toBe(0);
});

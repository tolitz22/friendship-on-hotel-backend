<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\RoomType;
use App\Models\Room;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('opens an exact reservation without relying on a paginated room list', function (): void {
    $hotel = Hotel::create(['name' => 'Direct Workflow Hotel', 'currency' => 'PHP']);
    $type = RoomType::create([
        'hotel_id' => $hotel->id,
        'name' => 'Standard',
        'max_occupancy' => 2,
    ]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P39-101']);
    $guest = $hotel->guests()->create(['first_name' => 'Direct', 'last_name' => 'Guest']);
    $admin = User::create([
        'name' => 'Direct Admin',
        'email' => uniqid('phase39-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);

    $base = '/api/v1/hotels/'.$hotel->id;
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase39-direct-'.uniqid(),
        'guestId' => $guest->id,
        'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10',
        'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');

    $this->getJson($base.'/reservation-records/'.$booking)
        ->assertOk()
        ->assertJsonPath('data.id', (string) $booking)
        ->assertJsonPath('data.hotelId', (string) $hotel->id)
        ->assertJsonPath('data.roomTypeId', (string) $type->id)
        ->assertJsonPath('data.assignedRoomId', null)
        ->assertJsonPath('data.lifecycleState', 'confirmed');

    $this->getJson($base.'/reservation-records/999999999')->assertNotFound();
});

it('never discloses a booking from another hotel or to a non-member', function (): void {
    $hotel = Hotel::create(['name' => 'Primary Direct Hotel']);
    $other = Hotel::create(['name' => 'Second Direct Hotel']);
    $type = RoomType::create([
        'hotel_id' => $hotel->id,
        'name' => 'Standard',
        'max_occupancy' => 2,
    ]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P39-201']);
    $guest = $hotel->guests()->create(['first_name' => 'Scoped', 'last_name' => 'Guest']);
    $admin = User::create([
        'name' => 'Scoped Admin',
        'email' => uniqid('phase39-scope-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);

    $base = '/api/v1/hotels/'.$hotel->id;
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase39-other-'.uniqid(),
        'guestId' => $guest->id,
        'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10',
        'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');

    $this->getJson('/api/v1/hotels/'.$other->id.'/reservation-records/'.$booking)
        ->assertNotFound();

    $member = User::create([
        'name' => 'Direct Read Staff',
        'email' => uniqid('phase39-read-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $member->id,
        'role' => 'front_desk', 'is_active' => true,
    ]);
    Sanctum::actingAs($member, ['hotel:read']);
    $this->getJson($base.'/reservation-records/'.$booking)
        ->assertOk()->assertJsonPath('data.id', (string) $booking);
});

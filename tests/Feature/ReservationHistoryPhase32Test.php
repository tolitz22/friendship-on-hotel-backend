<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists only the current hotel reservation audit events with actor and pagination', function (): void {
    $hotel = Hotel::create(['name' => 'History One']);
    $other = Hotel::create(['name' => 'History Two']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '3201']);
    $guest = $hotel->guests()->create(['first_name' => 'Audit', 'last_name' => 'Guest']);
    $staff = User::create([
        'name' => 'History Admin', 'email' => uniqid('history-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id, 'role' => 'admin', 'is_active' => true]);

    $base = '/api/v1/hotels/'.$hotel->id;
    $this->getJson($base.'/bookings/1/history')->assertUnauthorized();
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);

    $bookingId = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase32-history-001',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/bookings/'.$bookingId.'/assign-room', ['roomId' => $room->id])->assertOk();

    $this->getJson($base.'/bookings/'.$bookingId.'/history?per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.eventType', 'room_assigned')
        ->assertJsonPath('data.0.actorId', (string) $staff->id)
        ->assertJsonPath('meta.hotelId', (string) $hotel->id)
        ->assertJsonPath('meta.reservationId', (string) $bookingId)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.totalPages', 2);
    $this->getJson($base.'/bookings/'.$bookingId.'/history?per_page=1&page=2')
        ->assertOk()->assertJsonPath('data.0.eventType', 'created');

    $this->getJson($base.'/bookings/999999/history')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$other->id.'/bookings/'.$bookingId.'/history')
        ->assertNotFound();
});

it('allows an active hotel member to read history but forbids unscoped writes', function (): void {
    $hotel = Hotel::create(['name' => 'History Read Only']);
    $staff = User::create([
        'name' => 'History Reader', 'email' => uniqid('history-reader-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id, 'role' => 'front_desk', 'is_active' => true]);
    Sanctum::actingAs($staff, ['hotel:read']);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/bookings/999999/history')->assertNotFound();
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/bookings/999999/check-in')->assertForbidden();
});

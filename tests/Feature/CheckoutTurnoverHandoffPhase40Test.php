<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('links a completed checkout to the exact hotel-scoped cleaning task', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T09:00:00+08:00'));
    $hotel = Hotel::create([
        'name' => 'Phase40 Turnover Hotel',
        'timezone' => 'Asia/Manila',
        'currency' => 'PHP',
    ]);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '401']);
    $guest = $hotel->guests()->create(['first_name' => 'Turnover', 'last_name' => 'Guest']);
    $admin = User::create([
        'name' => 'Turnover Admin',
        'email' => uniqid('phase40-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $base = '/api/v1/hotels/'.$hotel->id;
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase40-book-'.uniqid(),
        'guestId' => $guest->id,
        'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10',
        'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');

    $turnover = $base.'/bookings/'.$booking.'/turnover';
    $this->getJson($turnover)->assertOk()
        ->assertJsonPath('data.bookingId', (string) $booking)
        ->assertJsonPath('data.task', null)
        ->assertJsonPath('data.determinesRoomReadiness', false);

    $this->patchJson($base.'/bookings/'.$booking.'/assign-room', [
        'roomId' => $room->id,
    ])->assertOk();
    $this->postJson($base.'/bookings/'.$booking.'/check-in')
        ->assertOk()->assertJsonPath('data.status', 'checked_in');

    $this->travelTo(new DateTimeImmutable('2026-12-12T09:00:00+08:00'));
    $this->postJson($base.'/bookings/'.$booking.'/check-out')
        ->assertOk()->assertJsonPath('data.status', 'checked_out');

    $res = $this->getJson($turnover)->assertOk()
        ->assertJsonPath('data.hotelId', (string) $hotel->id)
        ->assertJsonPath('data.bookingId', (string) $booking)
        ->assertJsonPath('data.task.type', 'checkout_clean')
        ->assertJsonPath('data.task.status', 'todo')
        ->assertJsonPath('data.task.roomId', (string) $room->id)
        ->assertJsonPath('data.determinesRoomReadiness', false);

    $taskId = $res->json('data.task.id');
    $this->getJson($base.'/housekeeping-tasks/'.$taskId)
        ->assertOk()->assertJsonPath('data.id', (string) $taskId);
    $this->getJson($base.'/bookings/9999999/turnover')->assertNotFound();

    $other = Hotel::create(['name' => 'Different Hotel']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/bookings/'.$booking.'/turnover')
        ->assertNotFound();

    $staff = User::create([
        'name' => 'Turnover Reader',
        'email' => uniqid('phase40-reader-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'housekeeping', 'is_active' => true,
    ]);
    Sanctum::actingAs($staff, ['hotel:read']);
    $this->getJson($turnover)->assertOk()
        ->assertJsonPath('data.task.id', (string) $taskId);
});

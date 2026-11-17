<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns only selected hotel room bookings and blocks for a week', function (): void {
    $hotel = Hotel::create(['name' => 'Calendar Test', 'timezone' => 'Asia/Manila']);
    $other = Hotel::create(['name' => 'Other Hotel']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $otherType = RoomType::create(['hotel_id' => $other->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '101']);
    $otherRoom = Room::create(['hotel_id' => $other->id, 'room_type_id' => $otherType->id, 'number' => '900']);
    DB::table('reservation_records')->insert([
        ['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
            'assigned_room_id' => $room->id, 'reference' => 'CAL-1',
            'check_in' => '2026-11-18', 'check_out' => '2026-11-20',
            'lifecycle_state' => 'confirmed'],
        ['hotel_id' => $other->id, 'room_type_id' => $otherType->id,
            'assigned_room_id' => $otherRoom->id, 'reference' => 'SECRET',
            'check_in' => '2026-11-18', 'check_out' => '2026-11-20',
            'lifecycle_state' => 'confirmed'],
        ['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
            'assigned_room_id' => null, 'reference' => 'UNASSIGNED',
            'check_in' => '2026-11-18', 'check_out' => '2026-11-20',
            'lifecycle_state' => 'confirmed'],
    ]);
    $staff = User::create(['name' => 'Calendar Admin', 'email' => 'calendar@example.test',
        'password' => 'fake-long-testing-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);

    $response = $this->getJson('/api/v1/hotels/'.$hotel->id.'/room-calendar?start=2026-11-17&end=2026-11-24');
    $response->assertOk()->assertJsonCount(1, 'data.rooms')
        ->assertJsonCount(1, 'data.bookings')
        ->assertJsonPath('data.bookings.0.reference', 'CAL-1')
        ->assertJsonPath('data.unassignedBookings', 1)
        ->assertJsonPath('meta.checkoutDateExclusive', true);
    expect($response->getContent())->not->toContain('SECRET');
    $this->getJson('/api/v1/hotels/'.$other->id.'/room-calendar?start=2026-11-17&end=2026-11-24')
        ->assertNotFound();
});

it('rejects more than a month and unauthenticated calendar requests', function (): void {
    $hotel = Hotel::create(['name' => 'Calendar Test']);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/room-calendar?start=2026-11-17&end=2026-11-24')
        ->assertUnauthorized();
    $staff = User::create(['name'=>'Test', 'email'=>'calendar-limits@example.test', 'password'=>'fake-testing-password']);
    HotelMembership::create(['hotel_id'=>$hotel->id, 'user_id'=>$staff->id, 'role'=>'admin']);
    Sanctum::actingAs($staff, ['hotel:read']);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/room-calendar?start=2026-11-17&end=2027-02-01')
        ->assertStatus(422);
});

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

it('searches reservation records by guest, reference, and physical room across server pages', function (): void {
    $hotel = Hotel::create(['name' => 'Reservation Desk UX']);
    $other = Hotel::create(['name' => 'Private Hotel']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $otherType = RoomType::create(['hotel_id' => $other->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '201']);
    $otherRoom = Room::create(['hotel_id' => $other->id, 'room_type_id' => $otherType->id, 'number' => '999']);
    $first = $hotel->guests()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
    $second = $hotel->guests()->create(['first_name' => 'Diego', 'last_name' => 'Reyes']);
    $foreign = $other->guests()->create(['first_name' => 'Maria', 'last_name' => 'Private']);

    $record = function (Hotel $property, int $roomTypeId, int $guestId, string $reference,
        ?int $roomId, string $state): void {
        DB::table('reservation_records')->insert([
            'hotel_id' => $property->id,
            'room_type_id' => $roomTypeId,
            'guest_id' => $guestId,
            'assigned_room_id' => $roomId,
            'reference' => $reference,
            'check_in' => '2027-02-10',
            'check_out' => '2027-02-11',
            'lifecycle_state' => $state,
        ]);
    };
    $record($hotel, $type->id, $second->id, 'DESK-REYES', null, 'confirmed');
    $record($hotel, $type->id, $first->id, 'DESK-MARIA', $room->id, 'checked_in');
    $record($other, $otherType->id, $foreign->id, 'PRIVATE-BOOKING', $otherRoom->id, 'confirmed');

    $staff = User::create([
        'name' => 'Desk Clerk', 'email' => uniqid('desk-', true).'@example.test',
        'password' => 'safe-test-password',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'front_desk', 'is_active' => true,
    ]);
    Sanctum::actingAs($staff, ['hotel:read', 'booking:write']);
    $url = '/api/v1/hotels/'.$hotel->id.'/reservation-records';

    $this->getJson($url.'?per_page=1&page=2')->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.totalPages', 2)
        ->assertJsonPath('data.0.guestName', 'Maria Santos');
    $this->getJson($url.'?search=Maria%20Santos&per_page=1')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.reference', 'DESK-MARIA')
        ->assertJsonPath('data.0.guestName', 'Maria Santos');
    $this->getJson($url.'?search=201')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.reference', 'DESK-MARIA');
    $this->getJson($url.'?search=DESK-REYES')->assertOk()
        ->assertJsonPath('data.0.guestName', 'Diego Reyes');
    $this->getJson($url.'?search=PRIVATE-BOOKING')->assertOk()
        ->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/hotels/'.$other->id.'/reservation-records')
        ->assertNotFound();
});

it('filters bookings by next operational action and validates user inputs', function (): void {
    $hotel = Hotel::create(['name' => 'Desk Filter Hotel']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '201']);
    $guest = $hotel->guests()->create(['first_name' => 'Guest', 'last_name' => 'Filter']);
    foreach ([
        ['NEEDS-ROOM', null, 'confirmed'],
        ['ASSIGNED', $room->id, 'confirmed'],
        ['IN-HOUSE', $room->id, 'checked_in'],
        ['COMPLETED', $room->id, 'checked_out'],
    ] as [$reference, $roomId, $state]) {
        DB::table('reservation_records')->insert([
            'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
            'guest_id' => $guest->id, 'assigned_room_id' => $roomId,
            'reference' => $reference, 'check_in' => '2027-02-10',
            'check_out' => '2027-02-11', 'lifecycle_state' => $state,
        ]);
    }
    $manager = User::create([
        'name' => 'Manager', 'email' => uniqid('mgr-', true).'@example.test',
        'password' => 'safe-test-password',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $manager->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $url = '/api/v1/hotels/'.$hotel->id.'/reservation-records';

    $this->getJson($url.'?filter=needs_room')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'NEEDS-ROOM');
    $this->getJson($url.'?filter=arrivals')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($url.'?filter=in_house')->assertOk()
        ->assertJsonPath('data.0.reference', 'IN-HOUSE');
    $this->getJson($url.'?filter=completed')->assertOk()
        ->assertJsonPath('data.0.reference', 'COMPLETED');
    $this->getJson($url.'?filter=unknown')->assertStatus(422);
    $this->getJson($url.'?per_page=200')->assertStatus(422);
    $this->getJson($url.'?search='.str_repeat('a', 101))->assertStatus(422);
});

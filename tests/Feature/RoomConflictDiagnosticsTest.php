<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function diagnosticStaff(Hotel $hotel): User
{
    $user = User::create([
        'name' => 'Diagnostic Desk',
        'email' => 'desk-'.uniqid().'@example.test',
        'password' => 'sample-password',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id,
        'user_id' => $user->id,
        'role' => 'front_desk',
        'is_active' => true,
    ]);

    return $user;
}

function diagnosticRecord(Hotel $hotel, RoomType $type, ?Room $room, string $reference, string $from, string $to): void
{
    DB::table('reservation_records')->insert([
        'hotel_id' => $hotel->id,
        'room_type_id' => $type->id,
        'assigned_room_id' => $room?->id,
        'reference' => $reference,
        'check_in' => $from,
        'check_out' => $to,
    ]);
}

it('reports real overlapping pairs but not adjacent nights or unassigned bookings', function (): void {
    $hotel = Hotel::create(['name' => 'Diagnostic Hotel']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '101']);
    $otherRoom = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '102']);

    diagnosticRecord($hotel, $type, $room, 'A', '2026-11-17', '2026-11-20');
    diagnosticRecord($hotel, $type, $room, 'B', '2026-11-20', '2026-11-22');
    diagnosticRecord($hotel, $type, $room, 'C', '2026-11-19', '2026-11-21');
    diagnosticRecord($hotel, $type, null, 'UNASSIGNED', '2026-11-18', '2026-11-21');
    diagnosticRecord($hotel, $type, $otherRoom, 'OTHER-ROOM', '2026-11-18', '2026-11-21');

    Sanctum::actingAs(diagnosticStaff($hotel), ['hotel:read']);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/reservation-overlaps?from=2026-11-17&to=2026-11-23')
        ->assertOk()
        ->assertJsonCount(3, 'data.records')
        ->assertJsonCount(2, 'data.overlappingPairs')
        ->assertJsonPath('data.overlappingPairs.0.firstReference', 'A')
        ->assertJsonPath('data.overlappingPairs.0.secondReference', 'C')
        ->assertJsonPath('data.overlappingPairs.1.firstReference', 'C')
        ->assertJsonPath('data.overlappingPairs.1.secondReference', 'B')
        ->assertJsonPath('meta.isAvailability', false)
        ->assertJsonPath('meta.scope', 'assigned_records_only_all_states');
});

it('rejects invalid reporting windows and inaccessible room IDs', function (): void {
    $hotel = Hotel::create(['name' => 'Permitted Hotel']);
    $other = Hotel::create(['name' => 'Other Hotel']);
    $type = RoomType::create(['hotel_id' => $other->id, 'name' => 'Suite', 'max_occupancy' => 4]);
    $room = Room::create(['hotel_id' => $other->id, 'room_type_id' => $type->id, 'number' => '403']);
    Sanctum::actingAs(diagnosticStaff($hotel), ['hotel:read']);

    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/reservation-overlaps';
    $this->getJson($base.'?from=2026-11-17&to=2026-11-20')->assertNotFound();

    $typeLocal = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $own = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $typeLocal->id, 'number' => '101']);
    $url = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$own->id.'/reservation-overlaps';
    $this->getJson($url.'?from=2026-11-20&to=2026-11-17')->assertUnprocessable();
    $this->getJson($url.'?from=2026-11-17&to=2027-01-17')->assertUnprocessable();
    $this->getJson($url.'?from=2026-02-30&to=2026-03-03')->assertUnprocessable();
});

it('requires active membership and read permission for diagnostics', function (): void {
    $hotel = Hotel::create(['name' => 'Protected Hotel']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '101']);
    $url = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/reservation-overlaps?from=2026-11-17&to=2026-11-20';
    $this->getJson($url)->assertUnauthorized();

    Sanctum::actingAs(diagnosticStaff($hotel), []);
    $this->getJson($url)->assertForbidden();
});

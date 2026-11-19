<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function rackFixture(): array
{
    $hotel = Hotel::create(['name' => 'PMS Rack Test', 'currency' => 'PHP', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $rooms = collect(range(1, 3))->map(fn (int $n) => Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'number' => 'RACK-'.$n, 'floor_number' => 4,
    ]));
    $actor = User::create(['name' => 'Rack Administrator',
        'email' => uniqid('rack-').'@example.test', 'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $actor->id,
        'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($actor, ['hotel:read', 'hotel:write']);
    return [$hotel, $type, $rooms, $actor];
}

it('reports hotel-wide PMS occupancy separately from physical readiness and future availability', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T10:00:00+08:00'));
    [$hotel, $type, $rooms] = rackFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $guest = $hotel->guests()->create(['first_name' => 'Rack', 'last_name' => 'Guest']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'room-rack-e2e-01',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-10',
    ]);
    // The reservation engine must reject zero-night stays independently of the rack.
    $booking->assertUnprocessable();

    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'room-rack-e2e-02',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-11',
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room', [
        'roomId' => $rooms[0]->id,
    ])->assertOk();
    $this->postJson($base.'/bookings/'.$booking.'/check-in')->assertOk();

    $rack = $this->getJson($base.'/rooms/rack?page=1&per_page=2');
    $rack->assertOk()->assertJsonPath('data.summary.totalRooms', 3)
        ->assertJsonPath('data.summary.pmsOccupiedRooms', 1)
        ->assertJsonPath('data.summary.pmsVacantRooms', 2)
        ->assertJsonPath('data.summary.arrivalsToday', 0)
        ->assertJsonPath('data.rooms.0.occupancy', 'occupied')
        ->assertJsonPath('data.rooms.0.currentStay.guestName', 'Rack Guest')
        ->assertJsonPath('data.rooms.0.roomTypeName', 'Standard')
        ->assertJsonPath('data.rooms.0.floor', 4)
        ->assertJsonPath('data.rooms.0.advisoryReady', false)
        ->assertJsonPath('meta.totalPages', 2)
        ->assertJsonPath('meta.availabilityGuaranteed', false)
        ->assertJsonPath('meta.physicalOccupancyVerified', false)
        ->assertJsonPath('meta.financialSettlementVerified', false)
        ->assertJsonCount(2, 'data.rooms');
    $this->getJson($base.'/rooms/rack?page=2&per_page=2')->assertOk()
        ->assertJsonPath('data.summary.pmsOccupiedRooms', 1)
        ->assertJsonCount(1, 'data.rooms');
    $this->getJson($base.'/rooms/rack?per_page=101')->assertUnprocessable();
});

it('shows unassigned arrivals separately and scopes guest-aware rack to hotel permission', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T10:00:00+08:00'));
    [$hotel, $type] = rackFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $guest = $hotel->guests()->create(['first_name' => 'Awaiting', 'last_name' => 'Room']);
    $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'room-rack-e2e-03',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertCreated();
    $this->getJson($base.'/rooms/rack')->assertOk()
        ->assertJsonPath('data.summary.arrivalsToday', 1)
        ->assertJsonPath('data.arrivals.0.guestName', 'Awaiting Room')
        ->assertJsonPath('data.arrivals.0.risk', 'unassigned_room');

    $other = Hotel::create(['name' => 'Other Rack Hotel', 'currency' => 'PHP']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/rooms/rack')->assertNotFound();
});

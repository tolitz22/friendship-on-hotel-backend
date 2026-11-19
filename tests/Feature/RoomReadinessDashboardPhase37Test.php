<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function phase37Fixture(): array
{
    $hotel = Hotel::create([
        'name' => 'Readiness Coverage Hotel', 'currency' => 'PHP',
        'timezone' => 'Asia/Manila',
    ]);
    $type = RoomType::create([
        'hotel_id' => $hotel->id, 'name' => 'Standard',
        'max_occupancy' => 2,
    ]);
    $first = Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '3701',
    ]);
    $second = Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '3702',
    ]);
    $actor = User::create([
        'name' => 'Readiness Dashboard Admin',
        'email' => uniqid('phase37-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $actor->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    Sanctum::actingAs($actor, ['hotel:read', 'hotel:write']);
    return [$hotel, $first, $second, $actor, $type];
}

it('reports complete hotel room totals with separately paginated room details', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T09:00:00+08:00'));
    [$hotel, $first, $second] = phase37Fixture();
    $base = '/api/v1/hotels/'.$hotel->id.'/readiness/dashboard';

    $this->getJson($base.'?days=7&room_per_page=1&room_page=1')
        ->assertOk()->assertJsonPath('data.hotelId', (string) $hotel->id)
        ->assertJsonPath('data.asOfHotelDate', '2026-12-10')
        ->assertJsonPath('data.arrivalWindowEndExclusive', '2026-12-17')
        ->assertJsonPath('data.summary.totalRooms', 2)
        ->assertJsonPath('data.summary.needsInspection', 2)
        ->assertJsonPath('data.summary.approvedCurrent', 0)
        ->assertJsonPath('data.enforceAtCheckIn', false)
        ->assertJsonPath('data.rooms.0.roomId', (string) $first->id)
        ->assertJsonPath('data.rooms.0.activeMaintenanceOrderId', null)
        ->assertJsonPath('data.rooms.0.activeInventoryBlockId', null)
        ->assertJsonPath('meta.roomTotalPages', 2)
        ->assertJsonPath('meta.snapshotOnly', true)
        ->assertJsonPath('meta.futureRoomReadinessGuaranteed', false)
        ->assertJsonPath('meta.inventoryGuaranteed', false)
        ->assertJsonCount(1, 'data.rooms');

    $this->getJson($base.'?room_per_page=1&room_page=2')
        ->assertOk()->assertJsonPath('data.rooms.0.roomId', (string) $second->id)
        ->assertJsonPath('data.summary.totalRooms', 2);
    $this->getJson($base.'?days=15')->assertUnprocessable();
    $this->getJson($base.'?room_per_page=101')->assertUnprocessable();
});

it('distinguishes an unassigned arrival from an assigned room needing inspection', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T09:00:00+08:00'));
    [$hotel, $first, $second, $actor, $type] = phase37Fixture();
    $guest = $hotel->guests()->create(['first_name' => 'Arrival', 'last_name' => 'Guest']);
    $base = '/api/v1/hotels/'.$hotel->id;
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase37-dashboard-02',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-11', 'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');

    $this->getJson($base.'/readiness/dashboard?days=7')
        ->assertOk()->assertJsonPath('meta.arrivalTotal', 1)
        ->assertJsonPath('data.arrivals.0.bookingId', (string) $booking)
        ->assertJsonPath('data.arrivals.0.risk', 'unassigned_room')
        ->assertJsonPath('data.arrivals.0.guestName', 'Arrival Guest')
        ->assertJsonPath('data.arrivals.0.roomBlockers', null)
        ->assertJsonPath('data.arrivals.0.openHousekeepingTaskId', null);

    $this->patchJson($base.'/bookings/'.$booking.'/assign-room', [
        'roomId' => $first->id,
    ])->assertOk();
    $this->getJson($base.'/readiness/dashboard?days=7')
        ->assertOk()->assertJsonPath('data.arrivals.0.risk', 'inspection_or_blocker')
        ->assertJsonPath('data.arrivals.0.roomStatus', 'needs_inspection')
        ->assertJsonPath('data.arrivals.0.roomBlockers.activeInventoryBlockToday', false)
        ->assertJsonPath('data.arrivals.0.roomBlockers.unfinishedHousekeeping', false);
});

it('shows approved coverage and invalidates it for subsequent completed room work', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T09:00:00+08:00'));
    [$hotel, $room] = phase37Fixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->postJson($base.'/rooms/'.$room->id.'/readiness/inspections', [
        'cleaningVerified' => true, 'safetyVerified' => true,
        'amenitiesVerified' => true, 'note' => 'Physical inspection completed for all required items',
    ])->assertCreated()->assertJsonPath('data.inspection.status', 'approved');

    $this->getJson($base.'/readiness/dashboard')->assertOk()
        ->assertJsonPath('data.summary.approvedCurrent', 1)
        ->assertJsonPath('data.summary.needsInspection', 1);

    $task = $this->postJson($base.'/housekeeping-tasks', [
        'roomId' => $room->id, 'type' => 'inspection',
        'note' => 'A second check was needed',
    ])->assertCreated()->json('data.id');
    $this->getJson($base.'/readiness/dashboard')->assertOk()
        ->assertJsonPath('data.rooms.0.openHousekeepingTaskId', (string) $task)
        ->assertJsonPath('data.rooms.0.blockers.unfinishedHousekeeping', true);
    $this->patchJson($base.'/housekeeping-tasks/'.$task, [
        'expectedRevision' => 1, 'status' => 'done',
        'note' => 'Second task finished but original room inspection is stale',
    ])->assertOk();

    $this->getJson($base.'/readiness/dashboard')->assertOk()
        ->assertJsonPath('data.summary.approvedCurrent', 0)
        ->assertJsonPath('data.summary.blockedOrStale', 1)
        ->assertJsonPath('data.rooms.0.status', 'blocked_or_stale')
        ->assertJsonPath('data.rooms.0.blockers.newRoomWorkSinceInspection', true);
});

it('does not disclose another hotel room readiness dashboard', function (): void {
    [$hotel] = phase37Fixture();
    $other = Hotel::create(['name' => 'Another Phase37 Hotel']);
    $url = '/api/v1/hotels/'.$other->id.'/readiness/dashboard';
    $this->getJson($url)->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/readiness/dashboard')
        ->assertOk()->assertJsonPath('data.summary.totalRooms', 2);
});

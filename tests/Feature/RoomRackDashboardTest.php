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
        ->assertJsonPath('data.summary.roomsNeedingCleaning', 0)
        ->assertJsonPath('data.summary.legacyPendingInspections', 0)
        ->assertJsonPath('data.summary.activelyBlockedRooms', 0)
        ->assertJsonPath('data.summary.arrivalsToday', 0)
        ->assertJsonPath('data.rooms.0.occupancy', 'occupied')
        ->assertJsonPath('data.rooms.0.currentStay.guestName', 'Rack Guest')
        ->assertJsonPath('data.rooms.0.roomTypeName', 'Standard')
        ->assertJsonPath('data.rooms.0.floor', 4)
        ->assertJsonPath('data.rooms.0.advisoryReady', false)
        ->assertJsonPath('data.allowedActions.completeInspection', true)
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

it('returns inspection affordances from active server membership, never from a client role claim', function (): void {
    [$hotel] = rackFixture();
    $staff = User::create(['name' => 'Reception Rack User',
        'email' => uniqid('rack-desk-').'@example.test', 'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'front_desk', 'is_active' => true]);
    Sanctum::actingAs($staff, ['hotel:read', 'booking:write']);
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->getJson($base.'/rooms/rack')->assertOk()
        ->assertJsonPath('data.allowedActions.completeInspection', false);
    Sanctum::actingAs($staff, ['hotel:read']);
    $this->postJson($base.'/rooms/1/readiness/inspections', [
        'cleaningVerified' => true,'safetyVerified' => true,
        'amenitiesVerified' => true,'note' => 'Reception cannot inspect a room',
    ])->assertForbidden();
});

it('shows a floor-groupable private housekeeping board without reservation identifiers or guest details', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T10:00:00+08:00'));
    [$hotel, $type, $rooms] = rackFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $cleaner = User::create(['name'=>'Board Cleaner','email'=>'hk-board@example.test','password'=>'Password123456!']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$cleaner->id,'role'=>'housekeeping','is_active'=>true]);
    $guest = $hotel->guests()->create(['first_name'=>'Private','last_name'=>'BoardGuest']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey'=>'hk-room-board-01','guestId'=>$guest->id,'roomTypeId'=>$type->id,
        'checkIn'=>'2026-12-10','checkOut'=>'2026-12-11',
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room',['roomId'=>$rooms[0]->id])->assertOk();
    $task = $this->postJson($base.'/housekeeping-tasks', [
        'roomId'=>$rooms[0]->id,'type'=>'checkout_clean','assignedUserId'=>$cleaner->id,
    ])->assertCreated()->json('data.id');
    $this->getJson($base.'/housekeeping/room-board')->assertForbidden();
    Sanctum::actingAs($cleaner,['hotel:read']);
    $response = $this->getJson($base.'/housekeeping/room-board')->assertOk()
        ->assertJsonPath('meta.total',3)->assertJsonPath('data.rooms.0.floor',4)
        ->assertJsonPath('data.rooms.0.stage','dirty')->assertJsonPath('data.rooms.0.arrivalToday',true)
        ->assertJsonPath('data.rooms.0.task.id',$task)->assertJsonPath('data.rooms.0.task.assignment','mine')
        ->assertJsonPath('data.rooms.1.stage','unverified');
    expect($response->getContent())->not->toContain('BoardGuest','bookingId','reference','activeStayId','guestName');
    $this->getJson($base.'/readiness/dashboard')->assertOk()
        ->assertJsonPath('meta.arrivalsVisible',false)->assertJsonCount(0,'data.arrivals');
    $this->getJson($base.'/rooms/rack')->assertForbidden();
    $other = Hotel::create(['name'=>'Foreign room board']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/housekeeping/room-board')->assertNotFound();
});

it('keeps occupancy separate from service and shows readiness only after cleaning and inspection', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T10:00:00+08:00'));
    [$hotel, $type, $rooms, $admin] = rackFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $cleaner = User::create(['name'=>'Board Service Cleaner','email'=>'hk-board-service@example.test','password'=>'Password123456!']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$cleaner->id,'role'=>'housekeeping','is_active'=>true]);
    $guest = $hotel->guests()->create(['first_name'=>'Occupied','last_name'=>'Room']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey'=>'hk-board-service-01','guestId'=>$guest->id,'roomTypeId'=>$type->id,
        'checkIn'=>'2026-12-10','checkOut'=>'2026-12-11',
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room',['roomId'=>$rooms[0]->id])->assertOk();
    $this->postJson($base.'/bookings/'.$booking.'/check-in')->assertOk();
    $this->postJson($base.'/housekeeping-tasks',['roomId'=>$rooms[0]->id,'type'=>'stayover','assignedUserId'=>$cleaner->id])->assertCreated();
    $task = $this->postJson($base.'/housekeeping-tasks',['roomId'=>$rooms[1]->id,'type'=>'checkout_clean','assignedUserId'=>$cleaner->id])->assertCreated()->json('data');
    $this->postJson($base.'/maintenance-work-orders',['roomId'=>$rooms[2]->id,'title'=>'Water leak','description'=>'Room cannot be used','priority'=>'high','affectsRoom'=>true])->assertCreated();
    Sanctum::actingAs($cleaner,['hotel:read']);
    $board = $base.'/housekeeping/room-board';
    $this->getJson($board)->assertOk()->assertJsonPath('data.rooms.0.occupancy','occupied')
        ->assertJsonPath('data.rooms.0.stage','service_due')->assertJsonPath('data.rooms.2.stage','blocked');
    $url=$base.'/housekeeping-tasks/'.$task['id'];
    $this->patchJson($url,['expectedRevision'=>1,'status'=>'in_progress'])->assertOk();
    $this->getJson($board)->assertJsonPath('data.rooms.1.stage','cleaning');
    $this->patchJson($url,['expectedRevision'=>2,'status'=>'done','checkedItems'=>array_column($task['checklist'],'key')])->assertOk();
    $this->getJson($board)->assertJsonPath('data.rooms.1.stage','inspection_due');
    $this->postJson($url.'/final-inspection',['expectedRevision'=>3,'cleaningVerified'=>true,'safetyVerified'=>true,'amenitiesVerified'=>true,'note'=>'All required physical checks complete'])->assertOk();
    $this->getJson($board)->assertJsonPath('data.rooms.1.stage','ready')->assertJsonPath('data.rooms.1.task',null)
        ->assertJsonPath('data.rooms.0.occupancy','occupied')->assertJsonPath('data.rooms.2.stage','blocked');
});

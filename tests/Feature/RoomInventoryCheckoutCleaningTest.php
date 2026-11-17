<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function inventoryCleanerStaff(Hotel $hotel, string $role): User
{
    $staff = User::create([
        'name' => 'Inventory '.$role,
        'email' => uniqid('inventory-clean-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => $role, 'is_active' => true,
    ]);
    return $staff;
}

it('tracks actual checkout cleaning until independent inspection approves the room', function (): void {
    $this->travelTo(new DateTimeImmutable('2027-01-10T09:00:00+08:00'));
    $hotel = Hotel::create([
        'name' => 'Checkout Cleaning Inventory',
        'timezone' => 'Asia/Manila', 'currency' => 'PHP',
    ]);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '201']);
    $guest = $hotel->guests()->create(['first_name' => 'Test', 'last_name' => 'Guest']);
    $manager = inventoryCleanerStaff($hotel, 'admin');
    $inspector = inventoryCleanerStaff($hotel, 'admin');
    $reception = inventoryCleanerStaff($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id;
    $roomStatus = $base.'/room-inventory-status?room_ids='.$room->id;

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->getJson($roomStatus)->assertOk()
        ->assertJsonPath('data.0.occupancy', 'vacant')
        ->assertJsonPath('data.0.cleaning', 'not_verified')
        ->assertJsonPath('data.0.advisoryReady', false)
        ->assertJsonPath('meta.physicalOccupancyVerified', false);

    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'cleaning-book-'.uniqid(),
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2027-01-10', 'checkOut' => '2027-01-12',
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room', ['roomId' => $room->id])->assertOk();
    $this->postJson($base.'/bookings/'.$booking.'/check-in')->assertOk();
    $this->getJson($roomStatus)->assertJsonPath('data.0.occupancy', 'occupied');

    $this->travelTo(new DateTimeImmutable('2027-01-12T09:00:00+08:00'));
    $this->getJson($roomStatus)->assertOk()
        ->assertJsonPath('data.0.occupancy', 'due_for_checkout')
        ->assertJsonPath('data.0.cleaning', 'not_verified');
    $this->postJson($base.'/bookings/'.$booking.'/check-out')->assertOk();
    $waiting = $this->getJson($roomStatus)->assertOk()
        ->assertJsonPath('data.0.occupancy', 'vacant')
        ->assertJsonPath('data.0.cleaning', 'checkout_cleaning')
        ->assertJsonPath('data.0.turnoverStatus', 'todo');
    $task = $waiting->json('data.0.turnoverTaskId');
    $this->assertNotNull($task);
    $this->assertDatabaseHas('reservation_turnover_links', [
        'hotel_id' => $hotel->id, 'reservation_record_id' => $booking,
        'housekeeping_task_id' => $task,
    ]);

    $this->patchJson($base.'/housekeeping-tasks/'.$task, [
        'expectedRevision' => 1, 'status' => 'in_progress',
        'note' => 'Staff has started the checkout clean.',
    ])->assertOk();
    $this->getJson($roomStatus)->assertJsonPath('data.0.cleaning', 'cleaning_in_progress');

    $this->patchJson($base.'/housekeeping-tasks/'.$task, [
        'expectedRevision' => 2, 'status' => 'done',
        'note' => 'Checkout clean completed and room prepared for inspection.',
    ])->assertOk();
    $this->getJson($roomStatus)->assertOk()
        ->assertJsonPath('data.0.cleaning', 'awaiting_inspection')
        ->assertJsonPath('data.0.cleanedById', (string) $manager->id)
        ->assertJsonPath('data.0.advisoryReady', false);

    $readiness = $base.'/rooms/'.$room->id.'/readiness';
    $inspection = $this->postJson($readiness.'/inspections', [
        'cleaningVerified' => true, 'safetyVerified' => true,
        'amenitiesVerified' => true, 'note' => 'Cleaning, amenities and safety checked.',
    ])->assertCreated()->json('data.inspection.id');
    $this->getJson($roomStatus)->assertJsonPath('data.0.cleaning', 'awaiting_inspection');

    Sanctum::actingAs($inspector, ['hotel:read', 'hotel:write']);
    $this->postJson($readiness.'/inspections/'.$inspection.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'Independently verified checkout cleaning and safety.',
    ])->assertOk();
    $this->getJson($roomStatus)->assertOk()
        ->assertJsonPath('data.0.cleaning', 'ready')
        ->assertJsonPath('data.0.advisoryReady', true)
        ->assertJsonPath('data.0.inspectionId', $inspection)
        ->assertJsonPath('data.0.reviewedById', (string) $inspector->id);

    Sanctum::actingAs($reception, ['hotel:read', 'booking:write']);
    $this->getJson($roomStatus)->assertOk()->assertJsonPath('data.0.cleaning', 'ready');
    $housekeeper = inventoryCleanerStaff($hotel, 'housekeeping');
    Sanctum::actingAs($housekeeper, ['hotel:read']);
    $this->getJson($roomStatus)->assertForbidden();
});

it('rejects unscoped, oversized and cross-hotel room queries', function (): void {
    $hotel = Hotel::create(['name' => 'Inventory A']);
    $other = Hotel::create(['name' => 'Inventory B']);
    $type = RoomType::create(['hotel_id' => $other->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $foreign = Room::create([
        'hotel_id' => $other->id, 'room_type_id' => $type->id, 'number' => '999',
    ]);
    $admin = inventoryCleanerStaff($hotel, 'admin');
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $base = '/api/v1/hotels/'.$hotel->id.'/room-inventory-status';
    $this->getJson($base.'?room_ids='.$foreign->id)->assertUnprocessable();
    $this->getJson($base.'?room_ids='.$foreign->id.','.$foreign->id)->assertUnprocessable();
    $this->getJson($base.'?room_ids=bad')->assertUnprocessable();
    $this->getJson('/api/v1/hotels/'.$other->id.'/room-inventory-status?room_ids='.$foreign->id)
        ->assertNotFound();
});

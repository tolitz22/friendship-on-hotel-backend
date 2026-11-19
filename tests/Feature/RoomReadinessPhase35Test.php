<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase35Staff(Hotel $hotel, string $role = 'admin'): User
{
    $staff = User::create([
        'name' => 'Readiness '.$role,
        'email' => uniqid('p35-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => $role, 'is_active' => true,
    ]);
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);
    return $staff;
}

function phase35Room(): array
{
    $hotel = Hotel::create(['name' => 'Phase35 Hotel', 'currency' => 'PHP']);
    $type = RoomType::create([
        'hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2,
    ]);
    $room = Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '3501',
    ]);
    $staff = phase35Staff($hotel);
    return [$hotel, $room, $staff];
}

function phase35Checklist(): array
{
    return [
        'cleaningVerified' => true,
        'safetyVerified' => true,
        'amenitiesVerified' => true,
        'note' => 'On-site physical checks completed',
    ];
}

it('completes physical room inspection without a manager approval and audits every completion', function (): void {
    [$hotel, $room] = phase35Room();
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness';
    $this->getJson($base)->assertOk()
        ->assertJsonPath('data.advisoryReady', false)
        ->assertJsonPath('data.inventoryGuaranteed', false);
    $first = $this->postJson($base.'/inspections', phase35Checklist())
        ->assertCreated()->assertJsonPath('data.inspection.status', 'approved')
        ->assertJsonPath('data.advisoryReady', true)->json('data.inspection.id');

    // A later physical inspection is allowed without a separate reviewer.
    $this->postJson($base.'/inspections', phase35Checklist())
        ->assertCreated()->assertJsonPath('data.advisoryReady', true);
    $this->postJson($base.'/inspections/'.$first.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'An earlier inspection cannot be modified',
    ])->assertNotFound();
    $this->getJson($base.'/history')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'approved')
        ->assertJsonPath('meta.total', 2);
});

it('rejects failed physical checks and active maintenance restrictions', function (): void {
    [$hotel, $room, $first] = phase35Room();
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness';
    $failed = phase35Checklist();
    $failed['safetyVerified'] = false;
    $this->postJson($base.'/inspections', $failed)->assertStatus(409);
    $this->getJson($base)->assertJsonPath('data.inspection', null);

    DB::table('maintenance_work_orders')->insert([
        'hotel_id' => $hotel->id, 'room_id' => $room->id,
        'title' => 'Broken AC', 'description' => 'Room is unsafe',
        'priority' => 'urgent', 'status' => 'open', 'affects_room' => true,
        'revision' => 1, 'created_by' => $first->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->postJson($base.'/inspections', phase35Checklist())->assertStatus(409);
    $this->getJson($base)->assertJsonPath('data.blockers.activeRoomImpactMaintenance', true)
        ->assertJsonPath('data.advisoryReady', false);
    $this->getJson($base.'/history')->assertJsonCount(0, 'data');
});

it('isolates hotel inspection history and blocks unauthorized review', function (): void {
    [$hotel, $room] = phase35Room();
    $other = Hotel::create(['name' => 'Other Phase35 Hotel', 'currency' => 'PHP']);
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness';
    $this->postJson($base.'/inspections', phase35Checklist())->assertCreated();
    $this->getJson('/api/v1/hotels/'.$other->id.'/rooms/'.$room->id.'/readiness')
        ->assertNotFound();

    phase35Staff($hotel, 'front_desk');
    $this->getJson($base)->assertOk();
    $this->getJson($base.'/history')->assertOk();
    $this->postJson($base.'/inspections', phase35Checklist())->assertForbidden();
});

it('rejects review attempts on already completed one-step inspections', function (): void {
    [$hotel, $room] = phase35Room();
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness';
    $inspection = $this->postJson($base.'/inspections', phase35Checklist())
        ->assertCreated()->json('data.inspection.id');
    $this->postJson($base.'/inspections/'.$inspection.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'Completed record should not be re-approved',
    ])->assertStatus(409);
    $this->getJson($base)->assertJsonPath('data.inspection.status', 'approved')
        ->assertJsonPath('data.advisoryReady', true);
});

it('allows a fresh on-site inspection to replace a legacy pending review while retaining audit history', function (): void {
    [$hotel, $room] = phase35Room();
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness';
    $old = $this->postJson($base.'/inspections', phase35Checklist())
        ->assertCreated()->json('data.inspection.id');
    DB::table('room_readiness_inspections')->where('id', $old)
        ->update(['status' => 'submitted']);
    $this->getJson($base)->assertJsonPath('data.advisoryReady', false);
    $new = $this->postJson($base.'/inspections', phase35Checklist())
        ->assertCreated()->assertJsonPath('data.inspection.status', 'approved')
        ->assertJsonPath('data.advisoryReady', true)->json('data.inspection.id');
    expect($new)->not->toBe($old);
    $this->getJson($base.'/history')->assertOk()->assertJsonPath('meta.total', 2);
    expect(DB::table('room_readiness_inspections')->where('id', $old)->value('status'))->toBe('submitted');
});

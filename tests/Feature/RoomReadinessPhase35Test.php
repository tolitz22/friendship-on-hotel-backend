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

it('stores a separate inspection and requires a second hotel administrator to approve', function (): void {
    [$hotel, $room] = phase35Room();
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness';
    $this->getJson($base)->assertOk()
        ->assertJsonPath('data.advisoryReady', false)
        ->assertJsonPath('data.enforcedAtCheckIn', false)
        ->assertJsonPath('data.inventoryGuaranteed', false);

    $inspection = $this->postJson($base.'/inspections', phase35Checklist())
        ->assertCreated()->assertJsonPath('data.inspection.status', 'submitted')
        ->json('data.inspection.id');

    $this->postJson($base.'/inspections', phase35Checklist())->assertStatus(409);
    $this->postJson($base.'/inspections/'.$inspection.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'Self review should not work',
    ])->assertForbidden();

    phase35Staff($hotel);
    $this->postJson($base.'/inspections/'.$inspection.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'Independent physical inspection review',
    ])->assertOk()->assertJsonPath('data.inspection.status', 'approved')
        ->assertJsonPath('data.advisoryReady', true)
        ->assertJsonPath('data.enforcedAtCheckIn', false);

    $this->postJson($base.'/inspections/'.$inspection.'/review', [
        'expectedRevision' => 1, 'decision' => 'reject',
        'note' => 'Duplicate review rejected',
    ])->assertStatus(409);
    $this->getJson($base.'/history')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'approved')
        ->assertJsonPath('meta.total', 2);
});

it('does not approve failed inspection checks or active maintenance blockers', function (): void {
    [$hotel, $room, $first] = phase35Room();
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness';

    $failed = phase35Checklist();
    $failed['safetyVerified'] = false;
    $id = $this->postJson($base.'/inspections', $failed)
        ->assertCreated()->json('data.inspection.id');
    phase35Staff($hotel);
    $this->postJson($base.'/inspections/'.$id.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'A failed safety inspection cannot pass',
    ])->assertStatus(409);
    $this->postJson($base.'/inspections/'.$id.'/review', [
        'expectedRevision' => 1, 'decision' => 'reject',
        'note' => 'Reject failed physical inspection',
    ])->assertOk()->assertJsonPath('data.inspection.status', 'rejected');

    DB::table('maintenance_work_orders')->insert([
        'hotel_id' => $hotel->id, 'room_id' => $room->id,
        'title' => 'Broken AC', 'description' => 'Room is unsafe',
        'priority' => 'urgent', 'status' => 'open',
        'affects_room' => true, 'revision' => 1,
        'created_by' => $first->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $id2 = $this->postJson($base.'/inspections', phase35Checklist())
        ->assertCreated()->json('data.inspection.id');
    $this->getJson($base)->assertJsonPath('data.blockers.activeRoomImpactMaintenance', true);
    // A different administrator must review the new inspection; otherwise
    // the self-approval guard returns 403 before evaluating blockers.
    phase35Staff($hotel);
    $this->postJson($base.'/inspections/'.$id2.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'Cannot approve during maintenance',
    ])->assertStatus(409);
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

it('rejects stale revisions, preserving the latest review state', function (): void {
    [$hotel, $room] = phase35Room();
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness';
    $inspection = $this->postJson($base.'/inspections', phase35Checklist())
        ->assertCreated()->json('data.inspection.id');
    phase35Staff($hotel);
    $this->postJson($base.'/inspections/'.$inspection.'/review', [
        'expectedRevision' => 9, 'decision' => 'approve',
        'note' => 'Stale inspection review',
    ])->assertStatus(409);
    $this->getJson($base)->assertJsonPath('data.inspection.status', 'submitted');
});

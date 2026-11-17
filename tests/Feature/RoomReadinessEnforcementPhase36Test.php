<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase36Admin(Hotel $hotel): User
{
    $staff = User::create([
        'name' => 'Phase36 Admin',
        'email' => uniqid('phase36-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);
    return $staff;
}

function phase36Booking(): array
{
    $hotel = Hotel::create([
        'name' => 'Phase 36 Hotel', 'currency' => 'PHP', 'timezone' => 'Asia/Manila',
    ]);
    $type = RoomType::create([
        'hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2,
    ]);
    $room = Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '3601',
    ]);
    $guest = $hotel->guests()->create(['first_name' => 'Phase', 'last_name' => 'ThirtySix']);
    $actor = phase36Admin($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $booking = test()->postJson($base.'/bookings', [
        'idempotencyKey' => 'p36-book-'.uniqid(),
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');
    test()->patchJson($base.'/bookings/'.$booking.'/assign-room', [
        'roomId' => $room->id,
    ])->assertOk();
    return [$hotel, $room, $booking, $actor];
}

it('keeps readiness check-in enforcement disabled unless the hotel explicitly activates it', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T09:00:00+08:00'));
    [$hotel, $room, $booking] = phase36Booking();
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->getJson($base.'/readiness/enforcement-policy')->assertOk()
        ->assertJsonPath('data.enforceAtCheckIn', false)
        ->assertJsonPath('data.revision', 0);
    $this->getJson($base.'/rooms/'.$room->id.'/readiness')->assertOk()
        ->assertJsonPath('data.enforcedAtCheckIn', false);
    $this->postJson($base.'/bookings/'.$booking.'/check-in')->assertOk()
        ->assertJsonPath('data.status', 'checked_in');
});

it('enforces independent current inspection only for an explicitly activated hotel', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T09:00:00+08:00'));
    [$hotel, $room, $booking] = phase36Booking();
    $base = '/api/v1/hotels/'.$hotel->id;
    $policy = $base.'/readiness/enforcement-policy';
    $this->patchJson($policy, [
        'expectedRevision' => 0, 'enforceAtCheckIn' => true,
        'reason' => 'Property manager approved mandatory physical room inspection',
    ])->assertOk()->assertJsonPath('data.enforceAtCheckIn', true)
        ->assertJsonPath('data.revision', 1);

    $this->postJson($base.'/bookings/'.$booking.'/check-in')->assertStatus(409);
    $this->getJson($base.'/rooms/'.$room->id.'/readiness')->assertOk()
        ->assertJsonPath('data.enforcedAtCheckIn', true);
    $inspection = $this->postJson($base.'/rooms/'.$room->id.'/readiness/inspections', [
        'cleaningVerified' => true, 'safetyVerified' => true,
        'amenitiesVerified' => true, 'note' => 'Physical check passed in guest room',
    ])->assertCreated()->json('data.inspection.id');

    $this->postJson($base.'/rooms/'.$room->id.'/readiness/inspections/'.$inspection.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'Inspector cannot approve their own work',
    ])->assertForbidden();
    phase36Admin($hotel);
    $this->postJson($base.'/rooms/'.$room->id.'/readiness/inspections/'.$inspection.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'Independent in-room inspection approved',
    ])->assertOk()->assertJsonPath('data.advisoryReady', true);

    $this->postJson($base.'/bookings/'.$booking.'/check-in')->assertOk()
        ->assertJsonPath('data.status', 'checked_in');
    $this->getJson($policy.'/history')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nextEnabled', true);
});

it('rejects stale policy changes and requires hotel administrators', function (): void {
    [$hotel] = phase36Booking();
    $base = '/api/v1/hotels/'.$hotel->id.'/readiness/enforcement-policy';
    $this->patchJson($base, [
        'expectedRevision' => 1, 'enforceAtCheckIn' => true,
        'reason' => 'Attempt with a stale policy revision',
    ])->assertStatus(409);
    $this->patchJson($base, [
        'expectedRevision' => 0, 'enforceAtCheckIn' => true,
        'reason' => 'Enable real hotel readiness requirements',
    ])->assertOk();
    $this->patchJson($base, [
        'expectedRevision' => 0, 'enforceAtCheckIn' => false,
        'reason' => 'Stale revision must not deactivate',
    ])->assertStatus(409);

    $member = User::create([
        'name' => 'Phase36 Reader',
        'email' => uniqid('phase36-reader-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $member->id,
        'role' => 'front_desk', 'is_active' => true,
    ]);
    Sanctum::actingAs($member, ['hotel:read', 'hotel:write']);
    $this->getJson($base)->assertOk();
    $this->patchJson($base, [
        'expectedRevision' => 1, 'enforceAtCheckIn' => false,
        'reason' => 'Unauthorized readiness deactivation',
    ])->assertForbidden();
});

it('checks current room-impact maintenance during enforced check-in even after approval', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T09:00:00+08:00'));
    [$hotel, $room, $booking, $actor] = phase36Booking();
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->patchJson($base.'/readiness/enforcement-policy', [
        'expectedRevision' => 0, 'enforceAtCheckIn' => true,
        'reason' => 'Make room inspection mandatory for arriving guests',
    ])->assertOk();
    $inspection = $this->postJson($base.'/rooms/'.$room->id.'/readiness/inspections', [
        'cleaningVerified' => true, 'safetyVerified' => true,
        'amenitiesVerified' => true, 'note' => 'Completed physical inspection of room',
    ])->assertCreated()->json('data.inspection.id');
    phase36Admin($hotel);
    $this->postJson($base.'/rooms/'.$room->id.'/readiness/inspections/'.$inspection.'/review', [
        'expectedRevision' => 1, 'decision' => 'approve',
        'note' => 'Independent room inspection has passed',
    ])->assertOk();

    $this->postJson($base.'/maintenance-work-orders', [
        'roomId' => $room->id, 'title' => 'Broken electric socket',
        'description' => 'Requires safe electrical repair before check-in',
        'priority' => 'urgent', 'affectsRoom' => true,
    ])->assertCreated();

    $this->postJson($base.'/bookings/'.$booking.'/check-in')->assertStatus(409);
    $this->getJson($base.'/rooms/'.$room->id.'/readiness')->assertOk()
        ->assertJsonPath('data.advisoryReady', false)
        ->assertJsonPath('data.blockers.activeRoomImpactMaintenance', true);
    expect(DB::table('reservation_records')->where('id', $booking)->value('lifecycle_state'))
        ->toBe('confirmed');
});

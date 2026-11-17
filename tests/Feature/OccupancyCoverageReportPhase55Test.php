<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase55User(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => 'Phase55 '. $role,
        'email' => uniqid('phase55-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    return $user;
}

function phase55Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Coverage Hotel', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $rooms = [];
    foreach (['101', '102', '103', '104'] as $number) {
        $rooms[] = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => $number]);
    }
    $manager = phase55User($hotel, 'admin');
    $worker = phase55User($hotel, 'housekeeping');
    $reception = phase55User($hotel, 'front_desk');
    $date = now('Asia/Manila')->toDateString();
    $base = '/api/v1/hotels/'.$hotel->id;
    return compact('hotel', 'type', 'rooms', 'manager', 'worker', 'reception', 'date', 'base');
}

function phase55Record(array $fx, int $roomIndex, string $status, ?string $discrepancy): int
{
    return DB::table('occupancy_verifications')->insertGetId([
        'hotel_id' => $fx['hotel']->id,
        'room_id' => $fx['rooms'][$roomIndex]->id,
        'assigned_user_id' => $fx['worker']->id,
        'created_by' => $fx['manager']->id,
        'observed_by' => $status === 'assigned' ? null : $fx['worker']->id,
        'reviewed_by' => in_array($status, ['verified', 'flagged'], true) ? $fx['manager']->id : null,
        'inspection_date' => $fx['date'],
        'status' => $status,
        'observed_occupancy' => $status === 'assigned' ? null : 'occupied',
        'expected_occupancy' => $status === 'assigned' ? null : 'vacant',
        'discrepancy' => $discrepancy,
        'observation_note' => $status === 'assigned' ? null : 'Factual observation recorded.',
        'expected_booking_ids' => $status === 'assigned' ? null : '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('computes manager daily coverage and distinguishes missing, pending, and mismatched rooms', function (): void {
    $fx = phase55Fixture();
    phase55Record($fx, 0, 'assigned', null);
    $verification = phase55Record($fx, 1, 'submitted', 'unexpected_occupied');
    phase55Record($fx, 2, 'flagged', 'unverified');

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $url = $fx['base'].'/occupancy-coverage-report?date='.$fx['date'];
    $this->getJson($url)->assertOk()
        ->assertJsonPath('summary.roomCount', 4)
        ->assertJsonPath('summary.missing', 1)
        ->assertJsonPath('summary.checked', 3)
        ->assertJsonPath('summary.assigned', 1)
        ->assertJsonPath('summary.submitted', 1)
        ->assertJsonPath('summary.flagged', 1)
        ->assertJsonPath('summary.observed', 2)
        ->assertJsonPath('summary.unexpectedOccupied', 1)
        ->assertJsonPath('summary.unverified', 1)
        ->assertJsonPath('summary.coveragePercent', 75)
        ->assertJsonPath('summary.observationPercent', 50)
        ->assertJsonPath('summary.historicalSnapshotOnly', true)
        ->assertJsonCount(4, 'data');

    $this->getJson($url.'&filter=missing')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.roomNumber', '104')
        ->assertJsonPath('data.0.status', 'missing');
    $this->getJson($url.'&filter=needs_review')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.verificationId', (string) $verification);
    $this->getJson($url.'&filter=mismatch')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.totalPages', 4)
        ->assertJsonCount(1, 'data');
});

it('requires the current hotel manager and never exposes coverage to reception or housekeeping', function (): void {
    $fx = phase55Fixture();
    $url = $fx['base'].'/occupancy-coverage-report';
    Sanctum::actingAs($fx['worker'], ['hotel:read']);
    $this->getJson($url)->assertForbidden();
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $this->getJson($url)->assertForbidden();
    Sanctum::actingAs($fx['manager'], ['hotel:read']);
    $this->getJson($url)->assertForbidden();
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->getJson($url.'?filter=invalid')->assertUnprocessable();
    $this->getJson($url.'?per_page=101')->assertUnprocessable();

    $other = Hotel::create(['name' => 'Unrelated Coverage Hotel']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/occupancy-coverage-report')->assertNotFound();
});

it('keeps prior inspection snapshots and does not misclassify missing rooms as vacant', function (): void {
    $fx = phase55Fixture();
    phase55Record($fx, 0, 'submitted', 'unexpected_occupied');

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $yesterday = now('Asia/Manila')->subDay()->toDateString();
    $this->getJson($fx['base'].'/occupancy-coverage-report?date='.$yesterday)
        ->assertOk()->assertJsonPath('summary.roomCount', 4)
        ->assertJsonPath('summary.observed', 0)
        ->assertJsonPath('summary.missing', 4)
        ->assertJsonPath('summary.unexpectedOccupied', 0)
        ->assertJsonPath('data.0.observedOccupancy', null);
    $this->getJson($fx['base'].'/occupancy-coverage-report?date='.$fx['date'])
        ->assertOk()->assertJsonPath('summary.unexpectedOccupied', 1);
    expect(DB::table('reservation_records')->where('hotel_id', $fx['hotel']->id)->count())->toBe(0);
});

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

it('keeps hotel exception records private and admin-only', function (): void {
    $hotel = Hotel::create(['name' => 'Synthetic Review Hotel', 'timezone' => 'Asia/Manila']);
    $another = Hotel::create(['name' => 'Unrelated Hotel']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $otherType = RoomType::create(['hotel_id' => $another->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $today = now('Asia/Manila')->toDateString();
    $tomorrow = now('Asia/Manila')->addDay()->toDateString();
    $after = now('Asia/Manila')->addDays(2)->toDateString();
    DB::table('reservation_records')->insert([
        ['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
            'reference' => 'SYNTHETIC-UNASSIGNED', 'check_in' => $tomorrow, 'check_out' => $after,
            'assigned_room_id' => null, 'lifecycle_state' => 'confirmed'],
        ['hotel_id' => $another->id, 'room_type_id' => $otherType->id,
            'reference' => 'OTHER-HOTEL-SECRET', 'check_in' => $tomorrow, 'check_out' => $after,
            'assigned_room_id' => null, 'lifecycle_state' => 'confirmed'],
    ]);
    $staff = User::create(['name' => 'Read Only Staff', 'email' => 'phase48-staff@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'staff', 'is_active' => true]);
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/operational-exceptions')->assertForbidden();

    HotelMembership::where('hotel_id', $hotel->id)->where('user_id', $staff->id)->update(['role' => 'admin']);
    $response = $this->getJson('/api/v1/hotels/'.$hotel->id.'/operational-exceptions?days=7');
    $response->assertOk()
        ->assertJsonPath('data.hotelId', (string) $hotel->id)
        ->assertJsonPath('data.summary.attention', 1)
        ->assertJsonPath('data.items.0.type', 'unassigned_arrival')
        ->assertJsonPath('meta.complete', true)
        ->assertJsonPath('meta.isFraudDetermination', false)
        ->assertJsonPath('meta.keycardLogsConnected', false);
    expect($response->getContent())->not->toContain('OTHER-HOTEL-SECRET');
    $this->getJson('/api/v1/hotels/'.$another->id.'/operational-exceptions')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/operational-exceptions?days=99')->assertUnprocessable();
});

it('reports assigned room overlaps as operational records without accusing staff', function (): void {
    $hotel = Hotel::create(['name' => 'Overlap Hotel', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '101']);
    $today = now('Asia/Manila')->toDateString();
    $firstOut = now('Asia/Manila')->addDays(3)->toDateString();
    $secondIn = now('Asia/Manila')->addDays(2)->toDateString();
    $secondOut = now('Asia/Manila')->addDays(4)->toDateString();
    DB::table('reservation_records')->insert([
        ['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'assigned_room_id' => $room->id,
            'reference' => 'SYNTHETIC-A', 'check_in' => $today, 'check_out' => $firstOut,
            'lifecycle_state' => 'confirmed'],
        ['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'assigned_room_id' => $room->id,
            'reference' => 'SYNTHETIC-B', 'check_in' => $secondIn, 'check_out' => $secondOut,
            'lifecycle_state' => 'confirmed'],
    ]);
    $admin = User::create(['name' => 'Admin', 'email' => 'phase48-admin@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $response = $this->getJson('/api/v1/hotels/'.$hotel->id.'/operational-exceptions');
    $response->assertOk()->assertJsonPath('data.summary.critical', 1)
        ->assertJsonPath('data.items.0.type', 'assigned_room_overlap');
    expect($response->getContent())->not->toContain('employee theft');
});

it('shows current inspection, occupancy and independent cashier review without exposing resolved cases', function (): void {
    $hotel = Hotel::create(['name' => 'Synthetic Action Hotel', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '201']);
    $room2 = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '202']);
    $manager = User::create(['name' => 'Reviewer Manager', 'email' => 'ops-reviewer@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    $worker = User::create(['name' => 'Observing Employee', 'email' => 'ops-observer@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $manager->id,
        'role' => 'admin', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $worker->id,
        'role' => 'front_desk', 'is_active' => true]);

    DB::table('room_readiness_inspections')->insert([
        'hotel_id' => $hotel->id, 'room_id' => $room->id,
        'status' => 'submitted', 'cleaning_verified' => 1,
        'safety_verified' => 1, 'amenities_verified' => 1,
        'inspector_note' => 'Room cleaning checks complete.',
        'inspected_by' => $worker->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $pending = DB::table('occupancy_verifications')->insertGetId([
        'hotel_id' => $hotel->id, 'room_id' => $room->id,
        'assigned_user_id' => $worker->id, 'created_by' => $manager->id,
        'status' => 'submitted', 'inspection_date' => now('Asia/Manila')->toDateString(),
        'observed_by' => $worker->id, 'discrepancy' => 'match',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $flagged = DB::table('occupancy_verifications')->insertGetId([
        'hotel_id' => $hotel->id, 'room_id' => $room2->id,
        'assigned_user_id' => $worker->id, 'created_by' => $manager->id,
        'status' => 'flagged', 'inspection_date' => now('Asia/Manila')->toDateString(),
        'observed_by' => $worker->id, 'reviewed_by' => $manager->id,
        'discrepancy' => 'unexpected_occupied', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('occupancy_investigations')->insert([
        'hotel_id' => $hotel->id, 'verification_id' => $flagged,
        'owner_id' => $manager->id, 'opened_by' => $manager->id,
        'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('cashier_shifts')->insert([
        'hotel_id' => $hotel->id, 'cashier_id' => $worker->id,
        'currency' => 'PHP', 'status' => 'submitted', 'opening_float_minor' => 0,
        'opened_at' => now()->subHour(), 'submitted_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $base = '/api/v1/hotels/'.$hotel->id.'/operational-exceptions';
    $result = $this->getJson($base)->assertOk()->json('data');
    expect($result['summary']['total'])->toBe(4);
    expect(collect($result['items'])->pluck('type')->sort()->values()->all())->toBe([
        'cashier_shift_review', 'occupancy_investigation', 'occupancy_review', 'room_inspection_review',
    ]);
    expect(collect($result['items'])->firstWhere('type', 'room_inspection_review')['ownerName'])->toBe($worker->name);
    expect(collect($result['items'])->firstWhere('type', 'occupancy_investigation')['roomNumber'])->toBe('202');
    expect(collect($result['items'])->firstWhere('type', 'cashier_shift_review')['ownerName'])->toBe($worker->name);
    DB::table('cashier_shifts')->insert([
        'hotel_id' => $hotel->id, 'cashier_id' => $worker->id,
        'currency' => 'PHP', 'status' => 'flagged', 'opening_float_minor' => 0,
        'opened_at' => now()->subHours(2), 'submitted_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $withFlag = $this->getJson($base)->assertOk()->json('data');
    expect($withFlag['summary']['total'])->toBe(5);
    expect($withFlag['summary']['critical'])->toBe(2);
    expect(collect($withFlag['items'])->pluck('type'))->toContain('cashier_shift_discrepancy');
    DB::table('occupancy_investigations')->where('verification_id', $flagged)->update([
        'status' => 'closed', 'closed_by' => $worker->id, 'closed_at' => now(),
    ]);
    $after = $this->getJson($base)->assertOk()->json('data');
    expect($after['summary']['total'])->toBe(4);
    expect(collect($after['items'])->pluck('type'))->not->toContain('occupancy_investigation');
    expect($pending)->toBeGreaterThan(0);
});

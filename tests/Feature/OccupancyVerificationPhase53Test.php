<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase53User(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => 'Phase 53 '.$role,
        'email' => uniqid('phase53-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id,
        'user_id' => $user->id,
        'role' => $role,
        'is_active' => true,
    ]);
    return $user;
}

function phase53Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Phase 53 Hotel', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P53-101']);
    $manager = phase53User($hotel, 'admin');
    $worker = phase53User($hotel, 'housekeeping');
    $otherWorker = phase53User($hotel, 'housekeeping');
    $reception = phase53User($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id.'/occupancy-verifications';
    $date = now('Asia/Manila')->toDateString();
    return compact('hotel','type','room','manager','worker','otherWorker','reception','base','date');
}

it('allows independent assigned housekeeping observation and flags an unexpected occupied room', function (): void {
    $fx = phase53Fixture();
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $id = $this->postJson($fx['base'], [
        'roomId' => $fx['room']->id, 'assignedUserId' => $fx['worker']->id,
        'inspectionDate' => $fx['date'],
    ])->assertCreated()->assertJsonPath('data.status','assigned')->json('data.id');

    Sanctum::actingAs($fx['worker'], ['hotel:read']);
    $this->postJson($fx['base'].'/'.$id.'/observe', [
        'observedOccupancy' => 'occupied',
        'note' => 'Guest presence reported from the corridor.',
    ])->assertOk()->assertJsonPath('data.status','submitted')
        ->assertJsonPath('data.expectedOccupancy','vacant')
        ->assertJsonPath('data.discrepancy','unexpected_occupied');
    $this->postJson($fx['base'].'/'.$id.'/observe', [
        'observedOccupancy' => 'vacant', 'note' => 'Attempt to replace evidence.',
    ])->assertStatus(409);

    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $this->postJson($fx['base'].'/'.$id.'/review', [
        'decision' => 'verified', 'note' => 'Cannot dismiss this mismatch.',
    ])->assertStatus(409);
    $this->postJson($fx['base'].'/'.$id.'/review', [
        'decision' => 'flagged', 'note' => 'Investigate independently with site supervisor.',
    ])->assertOk()->assertJsonPath('data.status','flagged');
    $this->postJson($fx['base'].'/'.$id.'/review', [
        'decision' => 'flagged', 'note' => 'Duplicate review is not allowed.',
    ])->assertStatus(409);
    expect(DB::table('occupancy_verification_events')->where('verification_id',$id)
        ->orderBy('id')->pluck('event')->all())->toBe(['assigned','observed','reviewed']);
    expect(DB::table('reservation_records')->where('hotel_id',$fx['hotel']->id)->count())->toBe(0);
});

it('prevents front desk involvement, unauthorized assignment, and cross-property reads', function (): void {
    $fx = phase53Fixture();
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $id = $this->postJson($fx['base'], [
        'roomId' => $fx['room']->id, 'assignedUserId' => $fx['worker']->id,
        'inspectionDate' => $fx['date'],
    ])->assertCreated()->json('data.id');
    $this->postJson($fx['base'], [
        'roomId' => $fx['room']->id, 'assignedUserId' => $fx['worker']->id,
        'inspectionDate' => $fx['date'],
    ])->assertStatus(409);
    $this->postJson($fx['base'], [
        'roomId' => $fx['room']->id, 'assignedUserId' => $fx['reception']->id,
        'inspectionDate' => $fx['date'],
    ])->assertStatus(422);

    Sanctum::actingAs($fx['reception'], ['hotel:read','booking:write']);
    $this->getJson($fx['base'])->assertForbidden();
    $this->postJson($fx['base'].'/'.$id.'/observe', [
        'observedOccupancy' => 'vacant', 'note' => 'Front desk cannot submit.',
    ])->assertForbidden();

    Sanctum::actingAs($fx['otherWorker'], ['hotel:read']);
    $this->getJson($fx['base'])->assertOk()->assertJsonCount(0,'data');
    $this->getJson($fx['base'].'/'.$id)->assertNotFound();
    $this->getJson($fx['base'].'/'.$id.'/history')->assertNotFound();
    $this->postJson($fx['base'].'/'.$id.'/observe', [
        'observedOccupancy' => 'vacant', 'note' => 'Wrong assigned worker.',
    ])->assertForbidden();

    $another = Hotel::create(['name' => 'Another Hotel']);
    Sanctum::actingAs($fx['worker'], ['hotel:read']);
    $this->getJson('/api/v1/hotels/'.$another->id.'/occupancy-verifications')->assertNotFound();
});

it('records a matching vacant observation and requires separate manager review', function (): void {
    $fx = phase53Fixture();
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $id = $this->postJson($fx['base'], [
        'roomId' => $fx['room']->id, 'assignedUserId' => $fx['worker']->id,
        'inspectionDate' => $fx['date'],
    ])->assertCreated()->json('data.id');
    Sanctum::actingAs($fx['worker'], ['hotel:read']);
    $this->postJson($fx['base'].'/'.$id.'/observe', [
        'observedOccupancy' => 'vacant', 'note' => 'Room appeared empty during authorized corridor check.',
    ])->assertOk()->assertJsonPath('data.discrepancy','match');
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $this->postJson($fx['base'].'/'.$id.'/review', [
        'decision' => 'verified', 'note' => 'Compared with assigned room roster.',
    ])->assertOk()->assertJsonPath('data.status','verified');
    $this->getJson($fx['base'].'/'.$id.'/history')->assertOk()->assertJsonCount(3,'data');
});

it('refuses stale-day observations and revoked housekeeping membership', function (): void {
    $fx = phase53Fixture();
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $id = $this->postJson($fx['base'], [
        'roomId' => $fx['room']->id, 'assignedUserId' => $fx['worker']->id,
        'inspectionDate' => $fx['date'],
    ])->assertCreated()->json('data.id');
    DB::table('occupancy_verifications')->where('id',$id)
        ->update(['inspection_date' => now('Asia/Manila')->subDay()->toDateString()]);
    Sanctum::actingAs($fx['worker'], ['hotel:read']);
    $this->postJson($fx['base'].'/'.$id.'/observe', [
        'observedOccupancy' => 'unknown', 'note' => 'Inspection happened too late.',
    ])->assertStatus(409);

    DB::table('hotel_memberships')->where('hotel_id',$fx['hotel']->id)
        ->where('user_id',$fx['worker']->id)->update(['is_active' => false]);
    $this->getJson($fx['base'])->assertNotFound();
    expect(DB::table('occupancy_verifications')->where('id',$id)->value('status'))->toBe('assigned');
});

it('matches a genuinely registered checked-in room without altering its booking record', function (): void {
    $fx = phase53Fixture();
    $guest = $fx['hotel']->guests()->create([
        'first_name' => 'Registered', 'last_name' => 'Occupant',
    ]);
    $checkIn = now('Asia/Manila')->subDay()->toDateString();
    $checkOut = now('Asia/Manila')->addDay()->toDateString();
    $id = DB::table('reservation_records')->insertGetId([
        'hotel_id' => $fx['hotel']->id,
        'room_type_id' => $fx['type']->id,
        'assigned_room_id' => $fx['room']->id,
        'guest_id' => $guest->id,
        'reference' => 'P53-OCCUPIED-'.uniqid(),
        'check_in' => $checkIn, 'check_out' => $checkOut,
        'lifecycle_state' => 'checked_in',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $observation = $this->postJson($fx['base'], [
        'roomId' => $fx['room']->id, 'assignedUserId' => $fx['worker']->id,
        'inspectionDate' => $fx['date'],
    ])->assertCreated()->json('data.id');
    Sanctum::actingAs($fx['worker'], ['hotel:read']);
    $this->postJson($fx['base'].'/'.$observation.'/observe', [
        'observedOccupancy' => 'occupied',
        'note' => 'Room appears occupied during authorized occupancy round.',
    ])->assertOk()->assertJsonPath('data.expectedOccupancy','occupied')
      ->assertJsonPath('data.discrepancy','match')
      ->assertJsonPath('data.expectedBookingIds.0',(string)$id);
    expect(DB::table('reservation_records')->where('id',$id)->value('lifecycle_state'))
        ->toBe('checked_in');
});

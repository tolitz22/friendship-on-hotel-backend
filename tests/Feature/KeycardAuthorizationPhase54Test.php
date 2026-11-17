<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase54Staff(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => 'Keycard '.$role, 'email' => uniqid('p54-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    return $user;
}

function phase54Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Phase 54 Hotel', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create([
        'hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2,
    ]);
    $room = Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P54-101',
    ]);
    $guest = $hotel->guests()->create(['first_name' => 'Keycard', 'last_name' => 'Guest']);
    $manager = phase54Staff($hotel, 'admin');
    $reception = phase54Staff($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id;
    return compact('hotel', 'type', 'room', 'guest', 'manager', 'reception', 'base');
}

function phase54CheckedIn(array $fx, $test): string
{
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $today = now('Asia/Manila')->toDateString();
    $out = now('Asia/Manila')->addDays(2)->toDateString();
    $booking = $test->postJson($fx['base'].'/bookings', [
        'idempotencyKey' => 'p54-book-'.uniqid(),
        'guestId' => $fx['guest']->id, 'roomTypeId' => $fx['type']->id,
        'checkIn' => $today, 'checkOut' => $out,
    ])->assertCreated()->json('data.id');
    $test->patchJson($fx['base'].'/bookings/'.$booking.'/assign-room', [
        'roomId' => $fx['room']->id,
    ])->assertOk();
    $test->postJson($fx['base'].'/bookings/'.$booking.'/check-in')
        ->assertOk()->assertJsonPath('data.status', 'checked_in');
    return (string) $booking;
}

it('requires a checked-in assigned current reservation and records only mock authorization', function (): void {
    $fx = phase54Fixture();
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $booking = $this->postJson($fx['base'].'/bookings', [
        'idempotencyKey' => 'p54-before-'.uniqid(),
        'guestId' => $fx['guest']->id, 'roomTypeId' => $fx['type']->id,
        'checkIn' => now('Asia/Manila')->toDateString(),
        'checkOut' => now('Asia/Manila')->addDays(2)->toDateString(),
    ])->assertCreated()->json('data.id');
    $endpoint = $fx['base'].'/keycard-authorizations';
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $body = ['idempotencyKey' => 'p54-initial-'.uniqid(),
        'kind' => 'initial', 'bookingId' => (int) $booking,
        'reason' => 'Guest has completed identification and check-in.'];
    $this->postJson($endpoint, $body)->assertStatus(409);

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->patchJson($fx['base'].'/bookings/'.$booking.'/assign-room', ['roomId' => $fx['room']->id])->assertOk();
    $this->postJson($fx['base'].'/bookings/'.$booking.'/check-in')->assertOk();
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $id = $this->postJson($endpoint, $body)->assertCreated()
        ->assertJsonPath('data.status', 'authorized_mock')
        ->assertJsonPath('data.physicalCardEncoded', false)
        ->assertJsonPath('data.provider', 'mock')->json('data.id');
    $this->postJson($endpoint, $body)->assertCreated()->assertJsonPath('data.id', $id);
    $this->postJson($endpoint, array_merge($body, ['reason' => 'Changed reason must conflict.']))
        ->assertStatus(409);
    $this->postJson($endpoint, array_merge($body, [
        'idempotencyKey' => 'p54-duplicate-'.uniqid(),
    ]))->assertStatus(409);
    expect(DB::table('keycard_authorization_events')->where('authorization_id', $id)
        ->orderBy('id')->pluck('event')->all())->toBe(['requested', 'authorized_mock']);
    expect(DB::table('keycard_authorizations')->where('hotel_id', $fx['hotel']->id)->count())->toBe(1);
});

it('requires independent manager approval for replacement and records revocation', function (): void {
    $fx = phase54Fixture();
    $booking = phase54CheckedIn($fx, $this);
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $endpoint = $fx['base'].'/keycard-authorizations';
    $this->postJson($endpoint, [
        'idempotencyKey' => 'p54-first-'.uniqid(), 'kind' => 'initial',
        'bookingId' => (int) $booking, 'reason' => 'Verified guest is checked in.',
    ])->assertCreated();

    $id = $this->postJson($endpoint, [
        'idempotencyKey' => 'p54-replace-'.uniqid(), 'kind' => 'replacement',
        'bookingId' => (int) $booking, 'reason' => 'Guest reported their original card lost.',
    ])->assertCreated()->assertJsonPath('data.status', 'pending_manager')->json('data.id');
    $this->postJson($endpoint, [
        'idempotencyKey' => 'p54-pending-duplicate-'.uniqid(), 'kind' => 'replacement',
        'bookingId' => (int) $booking,
        'reason' => 'Duplicate replacement request should not be accepted.',
    ])->assertStatus(409);
    $this->postJson($endpoint.'/'. $id.'/review', [
        'decision' => 'approve', 'note' => 'Self approval forbidden.',
    ])->assertForbidden();

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->postJson($endpoint.'/'.$id.'/review', [
        'decision' => 'approve', 'note' => 'Guest identity and room assignment checked separately.',
    ])->assertOk()->assertJsonPath('data.status', 'authorized_mock')
        ->assertJsonPath('data.physicalCardEncoded', false);
    $this->postJson($endpoint.'/'.$id.'/review', [
        'decision' => 'approve', 'note' => 'Duplicate approval forbidden.',
    ])->assertStatus(409);
    $this->postJson($endpoint.'/'.$id.'/revoke', [
        'note' => 'Mock authorization withdrawn after replacement review.',
    ])->assertOk()->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('data.physicalCardEncoded', false);
    $this->postJson($endpoint.'/'.$id.'/revoke', [
        'note' => 'Repeated revocation must not change the record.',
    ])->assertStatus(409);
    expect(DB::table('keycard_authorization_events')->where('authorization_id', $id)
        ->orderBy('id')->pluck('event')->all())
        ->toBe(['requested', 'approved', 'authorized_mock', 'revoked']);
});

it('restricts emergency requests to the correct room and independent hotel manager', function (): void {
    $fx = phase54Fixture();
    $endpoint = $fx['base'].'/keycard-authorizations';
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $id = $this->postJson($endpoint, [
        'idempotencyKey' => 'p54-emergency-'.uniqid(),
        'kind' => 'emergency_access', 'roomId' => $fx['room']->id,
        'reason' => 'Maintenance emergency reported requiring independent oversight.',
    ])->assertCreated()->assertJsonPath('data.status', 'pending_manager')->json('data.id');
    $this->postJson($endpoint.'/'.$id.'/review', [
        'decision' => 'approve', 'note' => 'Reception cannot review emergency access.',
    ])->assertForbidden();
    $this->getJson($endpoint.'/'. $id.'/history')->assertOk()->assertJsonCount(1, 'data');

    $otherHotel = Hotel::create(['name' => 'Unrelated Hotel']);
    $this->getJson('/api/v1/hotels/'.$otherHotel->id.'/keycard-authorizations')
        ->assertNotFound();
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->postJson($endpoint.'/'.$id.'/review', [
        'decision' => 'reject', 'note' => 'Emergency entry cannot be justified by independent evidence.',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('hides other receptionists requests and blocks housekeeping and read-only tokens', function (): void {
    $fx = phase54Fixture();
    $otherReception = phase54Staff($fx['hotel'], 'front_desk');
    $housekeeper = phase54Staff($fx['hotel'], 'housekeeping');
    $endpoint = $fx['base'].'/keycard-authorizations';
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $id = $this->postJson($endpoint, [
        'idempotencyKey' => 'p54-private-'.uniqid(), 'kind' => 'emergency_access',
        'roomId' => $fx['room']->id,
        'reason' => 'Observed door fault requiring independent manager review.',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($otherReception, ['hotel:read', 'booking:write']);
    $this->getJson($endpoint)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($endpoint.'/'.$id.'/history')->assertNotFound();

    Sanctum::actingAs($fx['reception'], ['hotel:read']);
    $this->postJson($endpoint, [
        'idempotencyKey' => 'p54-no-write-'.uniqid(), 'kind' => 'emergency_access',
        'roomId' => $fx['room']->id,
        'reason' => 'Read-only tokens cannot request access.',
    ])->assertForbidden();

    Sanctum::actingAs($housekeeper, ['hotel:read']);
    $this->getJson($endpoint)->assertForbidden();
    $this->postJson($endpoint, [
        'idempotencyKey' => 'p54-cleaner-'.uniqid(), 'kind' => 'emergency_access',
        'roomId' => $fx['room']->id, 'reason' => 'Housekeeping cannot authorize access.',
    ])->assertForbidden();
});

it('rejects a replacement after initial mock authorization is revoked and permits a manager to reject it', function (): void {
    $fx = phase54Fixture();
    $booking = phase54CheckedIn($fx, $this);
    $base = $fx['base'].'/keycard-authorizations';
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $initial = $this->postJson($base, [
        'idempotencyKey' => 'p54-revoke-first-'.uniqid(), 'kind' => 'initial',
        'bookingId' => (int) $booking, 'reason' => 'Original guest authorization recorded.',
    ])->assertCreated()->json('data.id');
    $replacement = $this->postJson($base, [
        'idempotencyKey' => 'p54-revoke-replace-'.uniqid(), 'kind' => 'replacement',
        'bookingId' => (int) $booking, 'reason' => 'Guest lost the previously authorized card.',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->postJson($base.'/'.$initial.'/revoke', [
        'note' => 'Guest requested original mock authorization be withdrawn.',
    ])->assertOk();
    $this->postJson($base.'/'.$replacement.'/review', [
        'decision' => 'approve', 'note' => 'Must revalidate original authorization on approval.',
    ])->assertStatus(409);
    $this->postJson($base.'/'.$replacement.'/review', [
        'decision' => 'reject', 'note' => 'Original authorization revoked. Close stale request.',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(DB::table('keycard_authorizations')->where('id', $replacement)->value('status'))
        ->toBe('rejected');
});

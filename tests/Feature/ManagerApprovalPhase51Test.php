<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase51Member(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => 'Approval '.$role,
        'email' => uniqid('phase51-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    return $user;
}

it('executes one independent manager-approved cancellation with complete audit history', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 51 Cancellation']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Basic', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P51-201']);
    $guest = $hotel->guests()->create(['first_name' => 'Cancel', 'last_name' => 'Guest']);
    $manager = phase51Member($hotel, 'admin');
    $reception = phase51Member($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase51-create-'.uniqid(), 'guestId' => $guest->id,
        'roomTypeId' => $type->id,
        'checkIn' => now()->addDays(10)->toDateString(),
        'checkOut' => now()->addDays(12)->toDateString(),
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($reception, ['hotel:read', 'booking:write']);
    $request = [
        'idempotencyKey' => 'phase51-request-'.uniqid(),
        'action' => 'cancel_booking',
        'reason' => 'Guest requested to cancel the upcoming stay.',
    ];
    $endpoint = $base.'/bookings/'.$booking.'/approval-requests';
    $approval = $this->postJson($endpoint, $request)->assertCreated()
        ->assertJsonPath('data.status', 'pending')->json('data.id');
    $this->postJson($endpoint, $request)->assertCreated()->assertJsonPath('data.id', $approval);
    $this->postJson($endpoint, array_merge($request, ['reason' => 'Changed request reason']))
        ->assertStatus(409);
    $this->postJson($base.'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve',
    ])->assertForbidden();

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->postJson($base.'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve', 'note' => 'Reviewed booking and accepted cancellation.',
    ])->assertOk()->assertJsonPath('data.status', 'executed')
        ->assertJsonPath('data.reviewedBy', (string) $manager->id);
    $this->postJson($base.'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve',
    ])->assertStatus(409);

    expect(DB::table('reservation_records')->where('id', $booking)->value('lifecycle_state'))
        ->toBe('cancelled');
    expect(DB::table('manager_approval_events')->where('approval_request_id', $approval)
        ->orderBy('id')->pluck('event')->all())->toBe(['requested', 'approved', 'executed']);
    expect(DB::table('reservation_booking_events')->where('reservation_record_id', $booking)
        ->where('actor_id', $manager->id)->where('event_type', 'cancelled')->count())->toBe(1);
});

it('does not allow self-approval or cross-hotel access and preserves rejected requests', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 51 Scope']);
    $other = Hotel::create(['name' => 'Phase 51 Other']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Basic', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P51-301']);
    $guest = $hotel->guests()->create(['first_name' => 'Scoped', 'last_name' => 'Guest']);
    $adminA = phase51Member($hotel, 'admin');
    $adminB = phase51Member($hotel, 'admin');
    $cleaner = phase51Member($hotel, 'housekeeping');
    $base = '/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($adminA, ['hotel:read', 'hotel:write']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase51-scoped-'.uniqid(), 'guestId' => $guest->id,
        'roomTypeId' => $type->id, 'checkIn' => now()->addDays(10)->toDateString(),
        'checkOut' => now()->addDays(11)->toDateString(),
    ])->assertCreated()->json('data.id');

    $approval = $this->postJson($base.'/bookings/'.$booking.'/approval-requests', [
        'idempotencyKey' => 'phase51-self-'.uniqid(),
        'action' => 'cancel_booking', 'reason' => 'Hotel administrator requests a cancellation.',
    ])->assertCreated()->json('data.id');
    $this->postJson($base.'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve',
    ])->assertForbidden();

    Sanctum::actingAs($cleaner, ['hotel:read']);
    $this->getJson($base.'/approval-requests')->assertForbidden();
    $this->postJson($base.'/bookings/'.$booking.'/approval-requests', [
        'idempotencyKey' => 'phase51-cleaner', 'action' => 'cancel_booking',
        'reason' => 'Unauthorized cancellation request.',
    ])->assertForbidden();

    Sanctum::actingAs($adminB, ['hotel:read', 'hotel:write']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/approval-requests')->assertNotFound();
    $this->postJson('/api/v1/hotels/'.$other->id.'/approval-requests/'.$approval.'/review', [
        'decision' => 'reject', 'note' => 'Wrong hotel',
    ])->assertNotFound();
    $this->postJson($base.'/approval-requests/'.$approval.'/review', [
        'decision' => 'reject', 'note' => 'Guest cancellation not verified.',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(DB::table('reservation_records')->where('id', $booking)->value('lifecycle_state'))
        ->toBe('confirmed');
});

it('rejects stale approvals without executing old date changes', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 51 Stale']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Basic', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P51-401']);
    $guest = $hotel->guests()->create(['first_name' => 'Stale', 'last_name' => 'Guest']);
    $manager = phase51Member($hotel, 'admin');
    $reception = phase51Member($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id;
    $in = now('Asia/Manila')->addDays(10)->toDateString();
    $out = now('Asia/Manila')->addDays(12)->toDateString();
    $newOut = now('Asia/Manila')->addDays(13)->toDateString();

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase51-stale-'.uniqid(), 'guestId' => $guest->id,
        'roomTypeId' => $type->id, 'checkIn' => $in, 'checkOut' => $out,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($reception, ['hotel:read', 'booking:write']);
    $approval = $this->postJson($base.'/bookings/'.$booking.'/approval-requests', [
        'idempotencyKey' => 'phase51-stale-request-'.uniqid(),
        'action' => 'amend_dates', 'reason' => 'Guest needs an extra night for the stay.',
        'checkIn' => $in, 'checkOut' => $newOut,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->patchJson($base.'/bookings/'.$booking.'/dates', [
        'checkIn' => $in, 'checkOut' => now('Asia/Manila')->addDays(14)->toDateString(),
    ])->assertOk();
    $this->postJson($base.'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve',
    ])->assertStatus(409);
    expect(DB::table('manager_approval_requests')->where('id', $approval)->value('status'))
        ->toBe('pending');
    expect(DB::table('manager_approval_events')->where('approval_request_id', $approval)->count())->toBe(1);
});

it('denies missing write ability and invalid date request payloads', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 51 Tokens']);
    $staff = phase51Member($hotel, 'front_desk');
    Sanctum::actingAs($staff, ['hotel:read']);
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->postJson($base.'/bookings/999999/approval-requests', [
        'idempotencyKey' => 'phase51-read-token',
        'action' => 'cancel_booking', 'reason' => 'Guest made cancellation request.',
    ])->assertForbidden();
    Sanctum::actingAs($staff, ['hotel:read', 'booking:write']);
    $this->postJson($base.'/bookings/999999/approval-requests', [
        'idempotencyKey' => 'phase51-invalid',
        'action' => 'amend_dates', 'reason' => 'Guest needs to change planned dates.',
        'checkIn' => '2026-12-12', 'checkOut' => '2026-12-10',
    ])->assertUnprocessable();
});

it('executes approved unpriced date amendments once and records the reviewer as actor', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 51 Dates', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Basic', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P51-501']);
    $guest = $hotel->guests()->create(['first_name' => 'Dates', 'last_name' => 'Guest']);
    $manager = phase51Member($hotel, 'admin');
    $reception = phase51Member($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id;
    $in = now('Asia/Manila')->addDays(10)->toDateString();
    $out = now('Asia/Manila')->addDays(11)->toDateString();
    $extended = now('Asia/Manila')->addDays(12)->toDateString();
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase51-amend-create-'.uniqid(), 'guestId' => $guest->id,
        'roomTypeId' => $type->id, 'checkIn' => $in, 'checkOut' => $out,
    ])->assertCreated()->json('data.id');
    Sanctum::actingAs($reception, ['hotel:read', 'booking:write']);
    $approval = $this->postJson($base.'/bookings/'.$booking.'/approval-requests', [
        'idempotencyKey' => 'phase51-amend-request-'.uniqid(),
        'action' => 'amend_dates', 'reason' => 'Guest requested one more night at the hotel.',
        'checkIn' => $in, 'checkOut' => $extended,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->postJson($base.'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve',
    ])->assertOk()->assertJsonPath('data.status', 'executed');
    expect(DB::table('reservation_records')->where('id', $booking)->value('check_out'))
        ->toBe($extended);
    expect(DB::table('reservation_date_amendments')->where('reservation_record_id', $booking)
        ->where('actor_id', $manager->id)->count())->toBe(1);
});

it('only returns a receptionist their own requests and blocks cross-request event reads', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 51 Privacy']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Basic', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P51-601']);
    $guest = $hotel->guests()->create(['first_name' => 'Private', 'last_name' => 'Guest']);
    $manager = phase51Member($hotel, 'admin');
    $a = phase51Member($hotel, 'front_desk');
    $b = phase51Member($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase51-privacy-create-'.uniqid(), 'guestId' => $guest->id,
        'roomTypeId' => $type->id,
        'checkIn' => now()->addDays(10)->toDateString(),
        'checkOut' => now()->addDays(11)->toDateString(),
    ])->assertCreated()->json('data.id');
    Sanctum::actingAs($a, ['hotel:read', 'booking:write']);
    $approval = $this->postJson($base.'/bookings/'.$booking.'/approval-requests', [
        'idempotencyKey' => 'phase51-privacy-request-'.uniqid(),
        'action' => 'cancel_booking', 'reason' => 'A request only this receptionist should read.',
    ])->assertCreated()->json('data.id');
    $this->getJson($base.'/approval-requests')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($base.'/approval-requests/'.$approval.'/history')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($b, ['hotel:read', 'booking:write']);
    $this->getJson($base.'/approval-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($base.'/approval-requests/'.$approval.'/history')->assertNotFound();
});

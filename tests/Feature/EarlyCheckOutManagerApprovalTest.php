<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function earlyDepartureUser(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => 'Early departure '.$role,
        'email' => uniqid('early-departure-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    return $user;
}

function earlyDepartureFixture($test): array
{
    $test->travelTo(new DateTimeImmutable('2026-12-10T12:00:00+08:00'));
    $hotel = Hotel::create(['name' => 'Early Departure Test', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'ED-101']);
    $guest = $hotel->guests()->create(['first_name' => 'Departing', 'last_name' => 'Guest']);
    $manager = earlyDepartureUser($hotel, 'admin');
    $reception = earlyDepartureUser($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $booking = $test->postJson($base.'/bookings', [
        'idempotencyKey' => 'early-departure-booking',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');
    $test->patchJson($base.'/bookings/'.$booking.'/assign-room', ['roomId' => $room->id])->assertOk();
    $test->postJson($base.'/bookings/'.$booking.'/check-in')->assertOk();
    return compact('hotel', 'room', 'manager', 'reception', 'base', 'booking');
}

it('requests independent manager early checkout and preserves dates, charges and turnover audit', function (): void {
    $fx = earlyDepartureFixture($this);
    $checkout = $fx['base'].'/bookings/'.$fx['booking'].'/check-out';
    $requests = $fx['base'].'/bookings/'.$fx['booking'].'/approval-requests';

    // Neither the regular endpoint nor a caller-provided approval ID bypasses dates.
    $this->postJson($checkout, ['earlyApprovalId' => 1])->assertStatus(409);
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $request = [
        'idempotencyKey' => 'early-departure-request',
        'action' => 'early_check_out',
        'reason' => 'The guest departed today and needs their stay closed early.',
    ];
    $approval = $this->postJson($requests, $request)
        ->assertCreated()->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.action', 'early_check_out')->json('data.id');
    $this->postJson($requests, $request)->assertCreated()->assertJsonPath('data.id', $approval);
    $this->postJson($requests, [...$request, 'idempotencyKey' => 'another-early-checkout-key'])
        ->assertStatus(409);
    $this->assertDatabaseHas('reservation_records', [
        'id' => $fx['booking'], 'lifecycle_state' => 'checked_in',
    ]);

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $review = $fx['base'].'/approval-requests/'.$approval.'/review';
    $this->postJson($review, ['decision' => 'approve'])->assertStatus(422);
    $this->postJson($review, [
        'decision' => 'approve',
        'note' => 'Confirmed guest departure and reviewed the outstanding balance.',
    ])->assertOk()->assertJsonPath('data.status', 'executed');
    $this->assertDatabaseHas('reservation_records', [
        'id' => $fx['booking'], 'lifecycle_state' => 'checked_out',
        'check_in' => '2026-12-10', 'check_out' => '2026-12-12',
    ]);
    $task = DB::table('reservation_turnover_links')
        ->where('hotel_id', $fx['hotel']->id)
        ->where('reservation_record_id', $fx['booking'])->first();
    expect($task)->not->toBeNull();
    expect(DB::table('housekeeping_tasks')->where('id', $task->housekeeping_task_id)
        ->where('type', 'checkout_clean')->count())->toBe(1);
    expect(DB::table('reservation_booking_events')->where('reservation_record_id', $fx['booking'])
        ->where('event_type', 'early_check_out')->count())->toBe(1);
    expect(DB::table('manager_approval_events')->where('approval_request_id', $approval)
        ->orderBy('id')->pluck('event')->all())->toBe(['requested', 'approved', 'executed']);
    $this->postJson($review, [
        'decision' => 'approve', 'note' => 'Another checkout cannot be executed.',
    ])->assertStatus(409);
    expect(DB::table('reservation_turnover_links')
        ->where('reservation_record_id', $fx['booking'])->count())->toBe(1);
});

it('cannot request early departure outside a checked-in stay or after scheduled checkout', function (): void {
    $fx = earlyDepartureFixture($this);
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $requests = $fx['base'].'/bookings/'.$fx['booking'].'/approval-requests';
    $this->travelTo(new DateTimeImmutable('2026-12-12T10:00:00+08:00'));
    $this->postJson($requests, [
        'idempotencyKey' => 'too-late-early-departure',
        'action' => 'early_check_out',
        'reason' => 'The guest already reached the scheduled departure date.',
    ])->assertStatus(409);
});

it('cannot self-approve an early checkout even with an administrator role', function (): void {
    $fx = earlyDepartureFixture($this);
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $approval = $this->postJson($fx['base'].'/bookings/'.$fx['booking'].'/approval-requests', [
        'idempotencyKey' => 'manager-early-departure',
        'action' => 'early_check_out',
        'reason' => 'Manager requested earlier departure for the guest.',
    ])->assertCreated()->json('data.id');
    $this->postJson($fx['base'].'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve', 'note' => 'Cannot authorize my own guest departure request.',
    ])->assertForbidden();
    $this->assertDatabaseHas('reservation_records', [
        'id' => $fx['booking'], 'lifecycle_state' => 'checked_in',
    ]);
});

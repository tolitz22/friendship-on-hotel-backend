<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function earlyArrivalMember(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => 'Early arrival '.$role,
        'email' => uniqid('early-arrival-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    return $user;
}

function earlyArrivalFixture(): array
{
    $hotel = Hotel::create(['name' => 'Early Arrival Hotel', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create([
        'hotel_id' => $hotel->id, 'name' => 'Standard',
        'max_occupancy' => 2,
    ]);
    $room = Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'EA-101',
    ]);
    $guest = $hotel->guests()->create(['first_name' => 'Early', 'last_name' => 'Guest']);
    $manager = earlyArrivalMember($hotel, 'admin');
    $reception = earlyArrivalMember($hotel, 'front_desk');
    $base = '/api/v1/hotels/'.$hotel->id;
    return compact('hotel','type','room','guest','manager','reception','base');
}

function earlyArrivalBooking(array $fx, $test, int $daysUntilArrival = 1): string
{
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $id = $test->postJson($fx['base'].'/bookings', [
        'idempotencyKey' => 'ea-book-'.uniqid(),
        'guestId' => $fx['guest']->id, 'roomTypeId' => $fx['type']->id,
        'checkIn' => now('Asia/Manila')->addDays($daysUntilArrival)->toDateString(),
        'checkOut' => now('Asia/Manila')->addDays($daysUntilArrival + 2)->toDateString(),
    ])->assertCreated()->json('data.id');
    $test->patchJson($fx['base'].'/bookings/'.$id.'/assign-room', [
        'roomId' => $fx['room']->id,
    ])->assertOk();
    return (string) $id;
}

it('requires manager-approved day-before check-in and retains the original stay with audited actual arrival', function (): void {
    $fx = earlyArrivalFixture();
    $booking = earlyArrivalBooking($fx, $this);
    $approvalEndpoint = $fx['base'].'/bookings/'.$booking.'/approval-requests';

    Sanctum::actingAs($fx['reception'], ['hotel:read','booking:write']);
    $this->postJson($fx['base'].'/bookings/'.$booking.'/check-in', [
        'earlyApprovalId' => 1,
    ])->assertStatus(409);
    $request = [
        'idempotencyKey' => 'ea-request-'.uniqid(),
        'action' => 'early_check_in',
        'reason' => 'Guest unexpectedly arrived a full day ahead of schedule.',
    ];
    $approval = $this->postJson($approvalEndpoint, $request)
        ->assertCreated()->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.action', 'early_check_in')->json('data.id');
    $this->postJson($approvalEndpoint, $request)->assertCreated()
        ->assertJsonPath('data.id', $approval);
    $this->postJson($approvalEndpoint, array_merge($request, [
        'idempotencyKey' => 'ea-duplicate-'.uniqid(),
    ]))->assertStatus(409);
    $this->postJson($fx['base'].'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve', 'note' => 'A manager cannot approve own request.',
    ])->assertForbidden();

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->postJson($fx['base'].'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve',
    ])->assertStatus(422);
    $this->postJson($fx['base'].'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve',
        'note' => 'Confirmed guest, checked room readiness, and reviewed possible extra-night charges.',
    ])->assertOk()->assertJsonPath('data.status', 'executed');
    $this->assertDatabaseHas('reservation_records', [
        'id' => $booking, 'lifecycle_state' => 'checked_in',
        'check_in' => now('Asia/Manila')->toDateString(),
    ]);
    $this->assertDatabaseHas('reservation_early_check_ins', [
        'hotel_id' => $fx['hotel']->id,
        'reservation_record_id' => $booking,
        'approval_request_id' => $approval,
        'requested_by' => $fx['reception']->id,
        'approved_by' => $fx['manager']->id,
        'checked_in_by' => $fx['manager']->id,
        'actual_check_in_date' => now('Asia/Manila')->toDateString(),
        'scheduled_check_in' => now('Asia/Manila')->addDay()->toDateString(),
    ]);
    $this->getJson($fx['base'].'/reservation-records/'.$booking)
        ->assertOk()->assertJsonPath('data.checkIn', now('Asia/Manila')->toDateString())
        ->assertJsonPath('data.lifecycleState', 'checked_in')
        ->assertJsonPath('data.earlyCheckIn.scheduledCheckIn', now('Asia/Manila')->addDay()->toDateString())
        ->assertJsonPath('data.earlyCheckIn.actualCheckInDate', now('Asia/Manila')->toDateString())
        ->assertJsonPath('data.earlyCheckIn.approvalRequestId', (string) $approval);
    expect(DB::table('reservation_booking_events')
        ->where('reservation_record_id', $booking)->where('event_type', 'early_check_in')->count())->toBe(1);
    expect(DB::table('manager_approval_events')->where('approval_request_id', $approval)
        ->orderBy('id')->pluck('event')->all())->toBe(['requested','approved','executed']);
    $this->postJson($fx['base'].'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve', 'note' => 'Second check-in must fail.',
    ])->assertStatus(409);
});

it('rejects requests outside the supported early-arrival day and respects hotel boundaries', function (): void {
    $fx = earlyArrivalFixture();
    $booking = earlyArrivalBooking($fx, $this, 3);
    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $this->postJson($fx['base'].'/bookings/'.$booking.'/approval-requests', [
        'idempotencyKey' => 'ea-too-early-'.uniqid(),
        'action' => 'early_check_in',
        'reason' => 'Guest claims to have arrived several days early.',
    ])->assertStatus(409);
    $foreign = Hotel::create(['name' => 'Other Early Hotel']);
    $this->getJson('/api/v1/hotels/'.$foreign->id.'/approval-requests')->assertNotFound();
    expect(DB::table('reservation_early_check_ins')->count())->toBe(0);
});

it('cannot use an early-arrival approval to displace a preceding guest in the same physical room', function (): void {
    $fx = earlyArrivalFixture();
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $today = now('Asia/Manila')->toDateString();
    $tomorrow = now('Asia/Manila')->addDay()->toDateString();
    $after = now('Asia/Manila')->addDays(3)->toDateString();
    $prior = $this->postJson($fx['base'].'/bookings', [
        'idempotencyKey' => 'ea-prior-'.uniqid(),
        'guestId' => $fx['guest']->id, 'roomTypeId' => $fx['type']->id,
        'checkIn' => $today, 'checkOut' => $tomorrow,
    ])->assertCreated()->json('data.id');
    $this->patchJson($fx['base'].'/bookings/'.$prior.'/assign-room', [
        'roomId' => $fx['room']->id,
    ])->assertOk();
    $future = $this->postJson($fx['base'].'/bookings', [
        'idempotencyKey' => 'ea-future-'.uniqid(),
        'guestId' => $fx['guest']->id, 'roomTypeId' => $fx['type']->id,
        'checkIn' => $tomorrow, 'checkOut' => $after,
    ])->assertCreated()->json('data.id');
    $this->patchJson($fx['base'].'/bookings/'.$future.'/assign-room', [
        'roomId' => $fx['room']->id,
    ])->assertOk();

    Sanctum::actingAs($fx['reception'], ['hotel:read', 'booking:write']);
    $approval = $this->postJson($fx['base'].'/bookings/'.$future.'/approval-requests', [
        'idempotencyKey' => 'ea-conflict-'.uniqid(), 'action' => 'early_check_in',
        'reason' => 'Early arrival while the prior guest is still registered.',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->postJson($fx['base'].'/approval-requests/'.$approval.'/review', [
        'decision' => 'approve',
        'note' => 'Review must detect the prior guest occupancy conflict.',
    ])->assertStatus(409);
    expect(DB::table('reservation_records')->where('id', $future)->value('lifecycle_state'))
        ->toBe('confirmed');
    expect(DB::table('manager_approval_requests')->where('id', $approval)->value('status'))
        ->toBe('pending');
    expect(DB::table('reservation_early_check_ins')->count())->toBe(0);
});

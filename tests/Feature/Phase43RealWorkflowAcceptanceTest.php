<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('accepts a persisted synthetic guest-to-checkout stay with payment review, audit and turnover', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-17 12:00:00', 'Asia/Manila'));
    $hotel = Hotel::create(['name' => 'E2E Fictional Hotel', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'E2E-101']);
    $admin = User::create(['name' => 'Acceptance Admin', 'email' => 'phase43-admin@example.test', 'password' => 'unused-test-password']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin']);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $base = '/api/v1/hotels/'.$hotel->id;

    $guestResponse = $this->postJson($base.'/guests', [
        'firstName' => 'Synthetic', 'lastName' => 'Guest', 'email' => 'synthetic-guest@example.test',
    ])->assertCreated()->assertJsonPath('data.firstName', 'Synthetic');
    $guestId = $guestResponse->json('data.id');

    $rateResponse = $this->postJson($base.'/rate-plans', [
        'roomTypeId' => $type->id, 'name' => 'E2E standard nightly', 'nightlyMinor' => 250000,
    ])->assertCreated();
    $rateId = $rateResponse->json('data.id');

    $payload = [
        'idempotencyKey' => 'phase43-booking-key-0001', 'guestId' => $guestId,
        'roomTypeId' => $type->id, 'ratePlanId' => $rateId,
        'checkIn' => '2026-11-17', 'checkOut' => '2026-11-18',
    ];
    $created = $this->postJson($base.'/bookings', $payload)
        ->assertCreated()->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.idempotentReplay', false);
    $bookingId = $created->json('data.id');
    $this->postJson($base.'/bookings', $payload)
        ->assertOk()->assertJsonPath('data.id', $bookingId)
        ->assertJsonPath('data.idempotentReplay', true);
    $this->assertDatabaseCount('reservation_records', 1);

    $this->postJson($base.'/bookings', [
        ...$payload, 'idempotencyKey' => 'phase43-booking-key-0002',
    ])->assertStatus(409);
    $this->assertDatabaseCount('reservation_records', 1);

    $this->patchJson($base.'/bookings/'.$bookingId.'/assign-room', ['roomId' => $room->id])
        ->assertOk()->assertJsonPath('data.roomId', (string) $room->id);
    $this->postJson($base.'/bookings/'.$bookingId.'/check-in')
        ->assertOk()->assertJsonPath('data.status', 'checked_in');
    $this->postJson($base.'/bookings/'.$bookingId.'/check-out')->assertStatus(409);

    $ledger = $this->getJson($base.'/bookings/'.$bookingId.'/folio-ledger')
        ->assertOk()->assertJsonPath('data.summary.roomSubtotalMinor', 250000)
        ->assertJsonPath('data.summary.isSettled', false)
        ->assertJsonPath('data.summary.bankOrGatewayVerified', false);
    $this->assertDatabaseHas('folio_entries', [
        'hotel_id' => $hotel->id, 'amount_minor' => 250000, 'entry_type' => 'room_night',
    ]);

    // Phase 52: cash must enter the same folio through an open shift. The
    // independent administrator reviews the payment and reconciles the drawer.
    $cashier = User::create([
        'name' => 'Acceptance Cashier',
        'email' => 'phase43-cashier@example.test',
        'password' => 'unused-test-password',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $cashier->id,
        'role' => 'front_desk', 'is_active' => true,
    ]);

    // Legacy direct cash posting is deliberately blocked, even for managers.
    $this->postJson($base.'/bookings/'.$bookingId.'/payment-records', [
        'requestKey' => 'phase43-old-direct-cash',
        'method' => 'cash', 'reference' => 'SYNTHETIC-LEGACY-CASH',
        'amountMinor' => 250000,
    ])->assertStatus(409);

    Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $shiftId = $this->postJson($base.'/cashier-shifts', [
        'openingFloatMinor' => 10000,
    ])->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
    $receiptUrl = $base.'/cashier-shifts/'.$shiftId.'/bookings/'.$bookingId.'/cash-receipts';
    $paymentInput = [
        'requestKey' => 'phase43-payment-key-001',
        'reference' => 'SYNTHETIC-CASH-001', 'amountMinor' => 250000,
    ];
    $payment = $this->postJson($receiptUrl, $paymentInput)
        ->assertCreated()->assertJsonPath('data.status', 'pending_review');
    $paymentId = $payment->json('data.paymentId');
    $this->postJson($receiptUrl, $paymentInput)
        ->assertOk()->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.paymentId', $paymentId);
    $this->assertDatabaseCount('folio_payment_records', 1);
    $this->assertDatabaseHas('cashier_shift_receipts', [
        'hotel_id' => $hotel->id, 'shift_id' => $shiftId,
        'reservation_record_id' => $bookingId,
        'payment_id' => $paymentId, 'amount_minor' => 250000,
    ]);
    $this->postJson($base.'/cashier-shifts/'.$shiftId.'/close', [
        'countedCashMinor' => 260000,
        'note' => 'Cash and opening float physically counted.',
    ])->assertOk()->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.expectedCashMinor', 260000)
        ->assertJsonPath('data.varianceMinor', 0);

    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $this->postJson($base.'/cashier-shifts/'.$shiftId.'/reconcile', [
        'decision' => 'reconcile', 'note' => 'Awaiting independent payment review.',
    ])->assertStatus(409);
    $this->postJson($base.'/bookings/'.$bookingId.'/payment-records/'.$paymentId.'/review', [
        'decision' => 'confirm', 'note' => 'Synthetic cash receipt verified by independent admin',
    ])->assertOk()->assertJsonPath('data.status', 'staff_confirmed');
    $this->postJson($base.'/cashier-shifts/'.$shiftId.'/reconcile', [
        'decision' => 'reconcile',
        'note' => 'Verified submitted drawer count and reviewed payment evidence.',
    ])->assertOk()->assertJsonPath('data.status', 'reconciled');
    expect(DB::table('cashier_shift_events')->where('shift_id', $shiftId)
        ->orderBy('id')->pluck('event')->all())
        ->toBe(['opened', 'cash_received', 'submitted', 'reconciled']);

    $this->getJson($base.'/bookings/'.$bookingId.'/folio-ledger')
        ->assertOk()->assertJsonPath('data.summary.staffConfirmedPaymentsMinor', 250000)
        ->assertJsonPath('data.summary.provisionalDifferenceMinor', 0)
        ->assertJsonPath('data.summary.bankOrGatewayVerified', false)
        ->assertJsonPath('data.summary.isSettled', false);

    Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00', 'Asia/Manila'));
    $this->postJson($base.'/bookings/'.$bookingId.'/check-out')
        ->assertOk()->assertJsonPath('data.status', 'checked_out');
    $this->postJson($base.'/bookings/'.$bookingId.'/check-out')->assertStatus(409);
    $this->assertDatabaseHas('reservation_records', [
        'id' => $bookingId, 'hotel_id' => $hotel->id, 'guest_id' => $guestId,
        'assigned_room_id' => $room->id, 'lifecycle_state' => 'checked_out',
    ]);

    $events = DB::table('reservation_booking_events')
        ->where('hotel_id', $hotel->id)->where('reservation_record_id', $bookingId)
        ->orderBy('id')->pluck('event_type')->all();
    expect($events)->toBe(['created', 'room_assigned', 'check_in', 'check_out']);
    expect(DB::table('reservation_booking_events')
        ->where('reservation_record_id', $bookingId)
        ->where('actor_id', $admin->id)->count())->toBe(4);

    $turnover = DB::table('reservation_turnover_links')
        ->where('hotel_id', $hotel->id)->where('reservation_record_id', $bookingId)->first();
    expect($turnover)->not->toBeNull();
    $this->assertDatabaseHas('housekeeping_tasks', [
        'id' => $turnover->housekeeping_task_id, 'hotel_id' => $hotel->id,
        'room_id' => $room->id, 'type' => 'checkout_clean', 'status' => 'todo',
    ]);
    expect(DB::table('reservation_turnover_links')
        ->where('reservation_record_id', $bookingId)->count())->toBe(1);
});

it('rejects cross-property guest and booking access and non-admin booking writes', function (): void {
    $hotel = Hotel::create(['name' => 'E2E Hotel A']);
    $other = Hotel::create(['name' => 'E2E Hotel B']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Single', 'max_occupancy' => 1]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'E2E-A']);
    $admin = User::create(['name' => 'E2E Admin', 'email' => 'phase43-isolation-admin@example.test', 'password' => 'unused-test-password']);
    $viewer = User::create(['name' => 'E2E Viewer', 'email' => 'phase43-isolation-viewer@example.test', 'password' => 'unused-test-password']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $viewer->id, 'role' => 'front_desk']);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $base = '/api/v1/hotels/'.$hotel->id;

    $guest = $this->postJson($base.'/guests', ['firstName' => 'Only', 'lastName' => 'Hotel A'])
        ->assertCreated()->json('data.id');
    $this->getJson('/api/v1/hotels/'.$other->id.'/guests/'.$guest)->assertNotFound();
    $payload = [
        'idempotencyKey' => 'phase43-isolation-key-001', 'guestId' => $guest,
        'roomTypeId' => $type->id, 'checkIn' => '2026-11-25', 'checkOut' => '2026-11-26',
    ];
    $this->postJson('/api/v1/hotels/'.$other->id.'/bookings', $payload)->assertNotFound();
    Sanctum::actingAs($viewer, ['hotel:read', 'hotel:write']);
    $this->postJson($base.'/bookings', $payload)->assertForbidden();
    expect(DB::table('reservation_records')->count())->toBe(0);
});

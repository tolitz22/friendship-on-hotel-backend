<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase25Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Phase 25 Finance Hotel', 'currency' => 'PHP']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '901']);
    $guest = $hotel->guests()->create(['first_name' => 'Jordan', 'last_name' => 'Guest']);
    $actor = User::create([
        'name' => 'Finance Manager', 'email' => uniqid('finance-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $actor->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($actor, ['hotel:read', 'hotel:write']);

    $rate = DB::table('room_rate_plans')->insertGetId([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'name' => 'BAR', 'nightly_minor' => 250000, 'currency' => 'PHP',
        'is_active' => true, 'created_by' => $actor->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $booking = test()->postJson('/api/v1/hotels/'.$hotel->id.'/bookings', [
        'idempotencyKey' => 'phase25-booking-'.uniqid(),
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'ratePlanId' => $rate, 'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');

    return [$hotel, $type, $guest, $actor, $booking];
}

function phase25Base(Hotel $hotel, string $booking): string
{
    return '/api/v1/hotels/'.$hotel->id.'/bookings/'.$booking;
}

it('calculates explicitly provisional ledger amounts without claiming tax or settlement', function (): void {
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $base = phase25Base($hotel, $booking);
    $this->postJson($base.'/folio-adjustments', [
        'requestKey' => 'adjust-debit-0001', 'kind' => 'debit',
        'amountMinor' => 50000, 'reason' => 'Manual minibar charge',
    ])->assertCreated();
    $this->postJson($base.'/folio-adjustments', [
        'requestKey' => 'adjust-credit-0001', 'kind' => 'credit',
        'amountMinor' => 25000, 'reason' => 'Manager goodwill discount',
    ])->assertCreated();

    $this->getJson($base.'/folio-ledger')->assertOk()
        ->assertJsonPath('data.summary.roomSubtotalMinor', 500000)
        ->assertJsonPath('data.summary.additionalDebitsMinor', 50000)
        ->assertJsonPath('data.summary.creditsMinor', 25000)
        ->assertJsonPath('data.summary.netRecordedChargesMinor', 525000)
        ->assertJsonPath('data.summary.isSettled', false)
        ->assertJsonPath('data.summary.finalPayableTotalKnown', false)
        ->assertJsonCount(2, 'data.adjustments')
        ->assertJsonCount(0, 'data.payments');
});

it('idempotently records adjustments and rejects conflicting retries', function (): void {
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $base = phase25Base($hotel, $booking);
    $body = [
        'requestKey' => 'adjust-duplicate-0001', 'kind' => 'credit',
        'amountMinor' => 10000, 'reason' => 'Manual credit correction',
    ];
    $id = $this->postJson($base.'/folio-adjustments', $body)->assertCreated()->json('data.id');
    $this->postJson($base.'/folio-adjustments', $body)->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.replayed', true);
    $this->postJson($base.'/folio-adjustments',
        array_replace($body, ['amountMinor' => 20000]))->assertStatus(409);
    expect(DB::table('folio_adjustments')->count())->toBe(1);
});

it('records pending bank evidence and requires independent staff review', function (): void {
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $base = phase25Base($hotel, $booking);
    $body = [
        'requestKey' => 'payment-evidence-0001',
        'method' => 'bank_transfer', 'reference' => 'BANK-REF-12345',
        'amountMinor' => 150000,
    ];
    $id = $this->postJson($base.'/payment-records', $body)
        ->assertCreated()->assertJsonPath('data.status', 'pending_review')->json('data.id');
    $this->postJson($base.'/payment-records', $body)
        ->assertOk()->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.replayed', true);
    $this->postJson($base.'/payment-records', array_replace($body, [
        'requestKey' => 'payment-evidence-0002',
    ]))->assertStatus(409);

    $this->getJson($base.'/folio-ledger')->assertOk()
        ->assertJsonPath('data.summary.pendingReviewPaymentsMinor', 150000)
        ->assertJsonPath('data.summary.staffConfirmedPaymentsMinor', 0);
    $this->postJson($base.'/payment-records/'.$id.'/review', [
        'decision' => 'confirm', 'note' => 'Matched against bank transaction listing',
    ])->assertForbidden();

    $reviewer = User::create([
        'name' => 'Independent Reviewer', 'email' => uniqid('review-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $reviewer->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    Sanctum::actingAs($reviewer, ['hotel:read', 'hotel:write']);
    $this->postJson($base.'/payment-records/'.$id.'/review', [
        'decision' => 'confirm', 'note' => 'Matched against bank transaction listing',
    ])->assertOk()->assertJsonPath('data.status', 'staff_confirmed');
    $this->postJson($base.'/payment-records/'.$id.'/review', [
        'decision' => 'reject', 'note' => 'Cannot change decision',
    ])->assertStatus(409);
    $this->getJson($base.'/folio-ledger')->assertOk()
        ->assertJsonPath('data.summary.pendingReviewPaymentsMinor', 0)
        ->assertJsonPath('data.summary.staffConfirmedPaymentsMinor', 150000)
        ->assertJsonPath('data.summary.provisionalDifferenceMinor', 350000)
        ->assertJsonPath('data.summary.bankOrGatewayVerified', false)
        ->assertJsonPath('data.summary.isSettled', false);
    expect(DB::table('folio_payment_records')->count())->toBe(1);
});

it('rejects payment evidence and excludes rejected amounts from provisional totals', function (): void {
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $base = phase25Base($hotel, $booking);
    $id = $this->postJson($base.'/payment-records', [
        'requestKey' => 'payment-reject-0001', 'method' => 'cash',
        'reference' => 'CASH-COUNTER-2026-0001', 'amountMinor' => 200000,
    ])->assertCreated()->json('data.id');
    $this->postJson($base.'/payment-records/'.$id.'/review', [
        'decision' => 'reject', 'note' => 'Duplicate cash log entry',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->getJson($base.'/folio-ledger')->assertOk()
        ->assertJsonPath('data.summary.staffConfirmedPaymentsMinor', 0)
        ->assertJsonPath('data.summary.pendingReviewPaymentsMinor', 0)
        ->assertJsonPath('data.summary.provisionalDifferenceMinor', 500000);
});

it('enforces hotel scope and staff permissions on all financial writes', function (): void {
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $other = Hotel::create(['name' => 'Other Finance Hotel', 'currency' => 'PHP']);
    $base = phase25Base($hotel, $booking);
    $this->getJson('/api/v1/hotels/'.$other->id.'/bookings/'.$booking.'/folio-ledger')->assertNotFound();
    $staff = User::create([
        'name' => 'Read Only Clerk', 'email' => uniqid('clerk-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'front_desk', 'is_active' => true,
    ]);
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);
    $this->getJson($base.'/folio-ledger')->assertOk();
    $this->postJson($base.'/folio-adjustments', [
        'requestKey' => 'staff-adjust-0001', 'kind' => 'credit',
        'amountMinor' => 10000, 'reason' => 'Unauthorized credit',
    ])->assertForbidden();
    $this->postJson($base.'/payment-records', [
        'requestKey' => 'staff-payment-0001', 'method' => 'cash',
        'reference' => 'NO-ACCESS-0001', 'amountMinor' => 10000,
    ])->assertForbidden();
    expect(DB::table('folio_adjustments')->count())->toBe(0);
    expect(DB::table('folio_payment_records')->count())->toBe(0);
});

it('does not invent a priced folio for legacy reservations', function (): void {
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $id = DB::table('reservation_records')->insertGetId([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'guest_id' => $guest->id, 'reference' => 'PHASE25-UNPRICED',
        'check_in' => '2026-12-14', 'check_out' => '2026-12-15',
        'lifecycle_state' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->getJson(phase25Base($hotel, (string) $id).'/folio-ledger')->assertNotFound();
    $this->postJson(phase25Base($hotel, (string) $id).'/folio-adjustments', [
        'requestKey' => 'unpriced-adjust-0001', 'kind' => 'debit',
        'amountMinor' => 1000, 'reason' => 'No priced folio',
    ])->assertNotFound();
});


it('reverses an unpaid priced booking atomically without claiming a refund', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 Asia/Manila'));
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $base = phase25Base($hotel, $booking);
    $this->postJson($base.'/cancel-priced-unpaid')->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.roomChargesReversedMinor', 500000)
        ->assertJsonPath('data.refundIssued', false);
    $this->postJson($base.'/cancel-priced-unpaid')->assertStatus(409);
    $this->getJson($base.'/folio-ledger')->assertOk()
        ->assertJsonPath('data.summary.roomSubtotalMinor', 500000)
        ->assertJsonPath('data.summary.creditsMinor', 500000)
        ->assertJsonPath('data.summary.netRecordedChargesMinor', 0)
        ->assertJsonPath('data.summary.isSettled', false);
    expect(DB::table('folio_adjustments')->count())->toBe(1);
    expect(DB::table('reservation_records')->find($booking)->lifecycle_state)->toBe('cancelled');
    expect(DB::table('reservation_booking_events')->where('event_type', 'cancelled_priced_unpaid')->count())->toBe(1);
});

it('refuses priced cancellation whenever payment evidence exists', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 Asia/Manila'));
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $base = phase25Base($hotel, $booking);
    $this->postJson($base.'/payment-records', [
        'requestKey' => 'cancellation-pmt-001', 'method' => 'bank_transfer',
        'reference' => 'TRANSFER-CANCEL-001', 'amountMinor' => 100000,
    ])->assertCreated();
    $this->postJson($base.'/cancel-priced-unpaid')->assertStatus(409);
    expect(DB::table('folio_adjustments')->count())->toBe(0);
    expect(DB::table('reservation_records')->find($booking)->lifecycle_state)->toBe('confirmed');
});

it('refuses priced cancellation on manually adjusted folios', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 Asia/Manila'));
    [$hotel, $type, $guest, $actor, $booking] = phase25Fixture();
    $base = phase25Base($hotel, $booking);
    $this->postJson($base.'/folio-adjustments', [
        'requestKey' => 'cancellation-adj-001', 'kind' => 'credit',
        'amountMinor' => 10000, 'reason' => 'Manager adjustment',
    ])->assertCreated();
    $this->postJson($base.'/cancel-priced-unpaid')->assertStatus(409);
    expect(DB::table('folio_adjustments')->count())->toBe(1);
    expect(DB::table('reservation_records')->find($booking)->lifecycle_state)->toBe('confirmed');
});

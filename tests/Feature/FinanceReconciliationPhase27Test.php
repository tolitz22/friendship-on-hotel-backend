<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase27Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Phase 27 Finance Property', 'currency' => 'PHP']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '2701']);
    $guest = $hotel->guests()->create(['first_name' => 'Gateway', 'last_name' => 'Guest']);
    $admin = User::create([
        'name' => 'Finance Admin',
        'email' => uniqid('p27-').'@example.test', 'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $rate = DB::table('room_rate_plans')->insertGetId([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'name' => 'P27 BAR', 'nightly_minor' => 10000, 'currency' => 'PHP',
        'is_active' => true, 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $booking = test()->postJson('/api/v1/hotels/'.$hotel->id.'/bookings', [
        'idempotencyKey' => 'phase27-booking-'.uniqid(),
        'guestId' => $guest->id, 'roomTypeId' => $type->id, 'ratePlanId' => $rate,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');
    return [$hotel, $booking, $admin];
}

function phase27EventPayload(string $reference = 'BANK-P27-TEST', int $amount = 10000, string $id = 'p27-event-0001'): array
{
    return [
        'eventId' => $id, 'eventType' => 'payment.observed',
        'reference' => $reference, 'amountMinor' => $amount,
        'currency' => 'PHP', 'occurredAt' => '2026-11-17T15:00:00+08:00',
    ];
}

function phase27Webhook($test, array $event, ?string $signature = null, ?string $timestamp = null)
{
    $raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $ts = $timestamp ?? (string) now()->timestamp;
    $sig = $signature ?? 'sha256='.hash_hmac('sha256', $ts.'.'.$raw, 'phase27-test-secret-should-have-32-bytes-minimum');
    return $test->call('POST', '/api/v1/integrations/finance/custom-hmac-v1/events',
        [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FINANCE_TIMESTAMP' => $ts,
            'HTTP_X_FINANCE_SIGNATURE' => $sig,
        ], $raw);
}

it('keeps the generic signed event adapter disabled by default', function (): void {
    config()->set('finance_gateway.custom_hmac_v1.enabled', false);
    config()->set('finance_gateway.custom_hmac_v1.secret', null);
    $this->postJson('/api/v1/integrations/finance/custom-hmac-v1/events', phase27EventPayload())
        ->assertStatus(503);
    expect(DB::table('finance_gateway_events')->count())->toBe(0);
});

it('rejects forged and stale signatures and never records financial settlement', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-17T15:00:00+08:00'));
    config()->set('finance_gateway.custom_hmac_v1.enabled', true);
    config()->set('finance_gateway.custom_hmac_v1.secret', 'phase27-test-secret-should-have-32-bytes-minimum');

    phase27Webhook($this, phase27EventPayload(), 'sha256='.str_repeat('0', 64))->assertUnauthorized();
    phase27Webhook($this, phase27EventPayload(), null, (string) (now()->timestamp - 500))->assertUnauthorized();
    expect(DB::table('finance_gateway_events')->count())->toBe(0);

    $id = phase27Webhook($this, phase27EventPayload())->assertStatus(202)
        ->assertJsonPath('data.status', 'unmapped')->json('data.id');
    phase27Webhook($this, phase27EventPayload())->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.replayed', true);
    phase27Webhook($this, phase27EventPayload('BANK-P27-DIFFERENT'))->assertStatus(409);
    expect(DB::table('finance_gateway_events')->count())->toBe(1);
    expect(DB::table('folio_payment_records')->count())->toBe(0);
    expect(DB::table('folio_refund_requests')->count())->toBe(0);
});

it('matches signed event references to a property payment without marking it settled', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-17T15:00:00+08:00'));
    config()->set('finance_gateway.custom_hmac_v1.enabled', true);
    config()->set('finance_gateway.custom_hmac_v1.secret', 'phase27-test-secret-should-have-32-bytes-minimum');

    [$hotel, $booking] = phase27Fixture();
    $base = '/api/v1/hotels/'.$hotel->id.'/bookings/'.$booking;
    $payment = $this->postJson($base.'/payment-records', [
        'requestKey' => 'p27-payment-0001', 'method' => 'bank_transfer',
        'reference' => 'BANK-P27-TEST', 'amountMinor' => 10000,
    ])->assertCreated()->json('data.id');
    $eventId = phase27Webhook($this, phase27EventPayload())->assertStatus(202)->json('data.id');

    $this->postJson($base.'/finance-event-reviews', [
        'eventId' => $eventId, 'paymentRecordId' => $payment,
        'note' => 'Reference and currency match only',
    ])->assertCreated()->assertJsonPath('data.assessment', 'reference_match')
        ->assertJsonPath('data.bankOrGatewayVerified', false)
        ->assertJsonPath('data.isSettled', false);
    $this->postJson($base.'/finance-event-reviews', [
        'eventId' => $eventId, 'paymentRecordId' => $payment,
        'note' => 'Duplicate review must be blocked',
    ])->assertStatus(409);
    $this->getJson($base.'/finance-event-reviews')
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($base.'/folio-ledger')->assertOk()
        ->assertJsonPath('data.summary.isSettled', false)
        ->assertJsonPath('data.summary.bankOrGatewayVerified', false);
    expect(DB::table('folio_payment_records')->find($payment)->status)->toBe('pending_review');
});

it('rejects mismatched amounts and forbids cross-property event reviews', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-17T15:00:00+08:00'));
    config()->set('finance_gateway.custom_hmac_v1.enabled', true);
    config()->set('finance_gateway.custom_hmac_v1.secret', 'phase27-test-secret-should-have-32-bytes-minimum');
    [$hotel, $booking] = phase27Fixture();
    $base = '/api/v1/hotels/'.$hotel->id.'/bookings/'.$booking;
    $payment = $this->postJson($base.'/payment-records', [
        'requestKey' => 'p27-payment-0002', 'method' => 'bank_transfer',
        'reference' => 'BANK-P27-TEST', 'amountMinor' => 11000,
    ])->assertCreated()->json('data.id');
    $eventId = phase27Webhook($this, phase27EventPayload())->assertStatus(202)->json('data.id');

    $this->postJson($base.'/finance-event-reviews', [
        'eventId' => $eventId, 'paymentRecordId' => $payment,
        'note' => 'Mismatch rejection test',
    ])->assertStatus(409);
    $outside = Hotel::create(['name' => 'Other Phase27 Hotel', 'currency' => 'PHP']);
    $this->getJson('/api/v1/hotels/'.$outside->id.'/bookings/'.$booking.'/finance-event-reviews')
        ->assertNotFound();
    expect(DB::table('finance_gateway_event_reviews')->count())->toBe(0);
});

it('requires a second administrator to approve a charge policy while keeping it ineffective', function (): void {
    [$hotel] = phase27Fixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $policy = $this->postJson($base.'/finance/charge-policy-drafts', [
        'taxBasisPoints' => 1000, 'serviceBasisPoints' => 500,
        'taxLabel' => 'Draft tax', 'serviceLabel' => 'Draft service',
        'reason' => 'Internal finance rate assessment',
    ])->assertCreated()->json('data.id');

    $this->postJson($base.'/finance/charge-policy-drafts/'.$policy.'/review', [
        'decision' => 'approve', 'note' => 'Same author must not approve',
    ])->assertForbidden();

    $reviewer = User::create([
        'name' => 'Independent Policy Reviewer', 'email' => uniqid('p27-review-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $reviewer->id,
        'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($reviewer, ['hotel:read', 'hotel:write']);
    $this->postJson($base.'/finance/charge-policy-drafts/'.$policy.'/review', [
        'decision' => 'approve', 'note' => 'Approved for internal review only',
    ])->assertOk()->assertJsonPath('data.status', 'reviewed')
        ->assertJsonPath('data.effectiveForBookings', false)
        ->assertJsonPath('data.legallyValidated', false);
    $this->postJson($base.'/finance/charge-policy-drafts/'.$policy.'/review', [
        'decision' => 'approve', 'note' => 'Duplicate approval must fail',
    ])->assertStatus(409);
    $this->getJson($base.'/finance/charge-policy-drafts')->assertOk()
        ->assertJsonPath('data.0.status', 'reviewed')
        ->assertJsonPath('data.0.effectiveForBookings', false);
});

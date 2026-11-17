<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function paymongo28Payload(string $id = 'evt_phase28_example_01', bool $live = false): array
{
    return [
        'data' => [
            'id' => $id,
            'type' => 'event',
            'attributes' => [
                'type' => 'checkout_session.payment.paid',
                'livemode' => $live,
                'created_at' => 1794900000,
                'data' => [
                    'id' => 'cs_phase28_example',
                    'type' => 'checkout_session',
                    'attributes' => [
                        'reference_number' => 'HOTEL-BOOKING-P28',
                    ],
                ],
            ],
        ],
    ];
}

function paymongo28Send($test, array $payload, ?string $signature = null, ?string $timestamp = null)
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $ts = $timestamp ?? (string) now()->timestamp;
    $te = $signature ?? hash_hmac('sha256', $ts.'.'.$raw, 'phase28-sandbox-webhook-secret-example-long');
    return $test->call('POST', '/api/v1/integrations/paymongo/sandbox/events',
        [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$ts.',te='.$te.',li=',
        ], $raw);
}

function paymongo28Configure(Hotel $hotel): void
{
    config()->set('paymongo_sandbox.enabled', true);
    config()->set('paymongo_sandbox.webhook_secret', 'phase28-sandbox-webhook-secret-example-long');
    config()->set('paymongo_sandbox.hotel_id', (string) $hotel->id);
}

it('keeps the PayMongo sandbox webhook disabled without explicit configuration', function (): void {
    config()->set('paymongo_sandbox.enabled', false);
    config()->set('paymongo_sandbox.webhook_secret', null);
    config()->set('paymongo_sandbox.hotel_id', null);
    paymongo28Send($this, paymongo28Payload())->assertStatus(503);
    expect(DB::table('paymongo_sandbox_events')->count())->toBe(0);
});

it('rejects forged, stale and live-mode PayMongo deliveries', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-17T15:00:00+08:00'));
    $hotel = Hotel::create(['name' => 'Sandbox Hotel', 'currency' => 'PHP']);
    paymongo28Configure($hotel);

    paymongo28Send($this, paymongo28Payload(), str_repeat('0', 64))
        ->assertStatus(401);
    paymongo28Send($this, paymongo28Payload(), null, (string) (now()->timestamp - 601))
        ->assertStatus(401);
    paymongo28Send($this, paymongo28Payload(live: true))
        ->assertStatus(422);
    expect(DB::table('paymongo_sandbox_events')->count())->toBe(0);
});

it('stores idempotent signed PayMongo sandbox metadata without financial postings', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-17T15:00:00+08:00'));
    $hotel = Hotel::create(['name' => 'Sandbox Hotel', 'currency' => 'PHP']);
    paymongo28Configure($hotel);
    $payload = paymongo28Payload();

    $id = paymongo28Send($this, $payload)->assertStatus(202)
        ->assertJsonPath('data.status', 'unmapped')
        ->assertJsonPath('data.isSettled', false)->json('data.id');
    paymongo28Send($this, $payload)->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.replayed', true);
    $changed = paymongo28Payload();
    $changed['data']['attributes']['data']['attributes']['reference_number'] = 'TAMPERED';
    paymongo28Send($this, $changed)->assertStatus(409);

    $row = DB::table('paymongo_sandbox_events')->first();
    expect((int) $row->hotel_id)->toBe((int) $hotel->id);
    expect($row->reference)->toBe('HOTEL-BOOKING-P28');
    expect($row->processing_status)->toBe('unmapped');
    expect(DB::table('paymongo_sandbox_events')->count())->toBe(1);
    expect(DB::table('folio_payment_records')->count())->toBe(0);
    expect(DB::table('folio_refund_requests')->count())->toBe(0);
});

it('requires configured hotel mapping and does not accept identity from event metadata', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-17T15:00:00+08:00'));
    $hotel = Hotel::create(['name' => 'Configured Merchant Hotel', 'currency' => 'PHP']);
    $other = Hotel::create(['name' => 'Different Hotel', 'currency' => 'PHP']);
    paymongo28Configure($hotel);

    $payload = paymongo28Payload('evt_phase28_bound_001');
    $payload['hotelId'] = $other->id;
    paymongo28Send($this, $payload)->assertStatus(202);
    expect((int) DB::table('paymongo_sandbox_events')->first()->hotel_id)->toBe((int) $hotel->id);

    config()->set('paymongo_sandbox.hotel_id', null);
    paymongo28Send($this, paymongo28Payload('evt_phase28_unbound_001'))->assertStatus(503);
    expect(DB::table('paymongo_sandbox_events')->count())->toBe(1);
});

it('lists sandbox observations only for an authorized hotel administrator', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-17T15:00:00+08:00'));
    $hotel = Hotel::create(['name' => 'Configured Hotel', 'currency' => 'PHP']);
    $other = Hotel::create(['name' => 'Other Hotel', 'currency' => 'PHP']);
    paymongo28Configure($hotel);
    paymongo28Send($this, paymongo28Payload())->assertStatus(202);

    $path = '/api/v1/hotels/'.$hotel->id.'/finance/paymongo-sandbox/events';
    $this->getJson($path)->assertUnauthorized();

    $staff = User::create(['name' => 'Finance Clerk', 'email' => uniqid('p28-').'@example.test',
        'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'front_desk', 'is_active' => true]);
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);
    $this->getJson($path)->assertForbidden();

    $admin = User::create(['name' => 'Finance Administrator',
        'email' => uniqid('p28-admin-').'@example.test', 'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $this->getJson($path)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.mode', 'test')
        ->assertJsonPath('data.0.bankOrGatewayVerified', false)
        ->assertJsonPath('data.0.isSettled', false);
    $this->getJson('/api/v1/hotels/'.$other->id.'/finance/paymongo-sandbox/events')
        ->assertNotFound();
});

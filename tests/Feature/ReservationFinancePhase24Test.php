<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase24Hotel(): array
{
    $hotel = Hotel::create(['name' => 'Rate Test Hotel', 'currency' => 'PHP']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '701']);
    $guest = $hotel->guests()->create(['first_name' => 'Rate', 'last_name' => 'Guest']);
    return [$hotel, $type, $guest];
}

function phase24Staff(Hotel $hotel, string $role = 'admin', array $permissions = ['hotel:read','hotel:write']): void
{
    $user = User::create([
        'name' => 'Rate Staff', 'email' => uniqid('rate-').'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    Sanctum::actingAs($user, $permissions);
}

function phase24Rate($test, Hotel $hotel, RoomType $type, int $amount = 250000): string
{
    return $test->postJson('/api/v1/hotels/'.$hotel->id.'/rate-plans', [
        'roomTypeId' => $type->id, 'name' => 'Standard BAR', 'nightlyMinor' => $amount,
    ])->assertCreated()->json('data.id');
}

it('quotes explicit fixed nightly prices without inventing tax or payable totals', function (): void {
    [$hotel, $type, $guest] = phase24Hotel();
    phase24Staff($hotel);
    $rate = phase24Rate($this, $hotel, $type);
    $base = '/api/v1/hotels/'.$hotel->id;

    $this->getJson($base.'/rate-plans')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.currency', 'PHP')
        ->assertJsonPath('data.0.nightlyMinor', 250000);

    $this->getJson($base.'/room-quotes?roomTypeId='.$type->id.'&ratePlanId='.$rate.
        '&checkIn=2026-12-10&checkOut=2026-12-13')
        ->assertOk()->assertJsonPath('data.roomSubtotalMinor', 750000)
        ->assertJsonPath('data.currency', 'PHP')
        ->assertJsonPath('data.nights.2', '2026-12-12')
        ->assertJsonPath('data.isFinalPayableTotal', false)
        ->assertJsonPath('data.taxesCalculated', false);
});

it('creates a priced booking with a permanent nightly folio snapshot and idempotent replay', function (): void {
    [$hotel, $type, $guest] = phase24Hotel();
    phase24Staff($hotel);
    $rate = phase24Rate($this, $hotel, $type);
    $base = '/api/v1/hotels/'.$hotel->id;
    $data = [
        'idempotencyKey' => 'phase24-priced-001',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'ratePlanId' => $rate, 'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ];

    $id = $this->postJson($base.'/bookings', $data)->assertCreated()->json('data.id');
    $this->postJson($base.'/bookings', $data)->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.idempotentReplay', true);
    $this->getJson($base.'/bookings/'.$id.'/folio')->assertOk()
        ->assertJsonPath('data.roomSubtotalMinor', 500000)
        ->assertJsonPath('data.currency', 'PHP')
        ->assertJsonPath('data.isFinalPayableTotal', false)
        ->assertJsonCount(2, 'data.entries');

    expect(DB::table('reservation_folios')->count())->toBe(1);
    expect(DB::table('folio_entries')->count())->toBe(2);
    $this->patchJson($base.'/bookings/'.$id.'/dates', [
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-13',
    ])->assertStatus(409);
    $this->postJson($base.'/bookings/'.$id.'/cancel')->assertStatus(409);
    expect(DB::table('reservation_records')->find($id)->lifecycle_state)->toBe('confirmed');
    expect(DB::table('folio_entries')->sum('amount_minor'))->toBe(500000);
});

it('preserves unpriced legacy bookings without an invented folio', function (): void {
    [$hotel, $type, $guest] = phase24Hotel();
    phase24Staff($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $id = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase24-legacy-001',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');

    $this->getJson($base.'/bookings/'.$id.'/folio')->assertOk()
        ->assertJsonPath('data', null)->assertJsonPath('meta.priced', false);
    expect(DB::table('reservation_folios')->count())->toBe(0);
});

it('requires tenant membership and rejects other property rates and folios', function (): void {
    [$hotel, $type, $guest] = phase24Hotel();
    [$other, $otherType, $otherGuest] = phase24Hotel();
    phase24Staff($hotel);
    $rate = phase24Rate($this, $hotel, $type);
    $base = '/api/v1/hotels/'.$hotel->id;

    $this->getJson($base.'/room-quotes?roomTypeId='.$otherType->id.'&ratePlanId='.$rate.
        '&checkIn=2026-12-10&checkOut=2026-12-12')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$other->id.'/rate-plans')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$other->id.'/bookings/1/folio')->assertNotFound();
    $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase24-bad-rate-1', 'guestId' => $guest->id,
        'roomTypeId' => $type->id, 'ratePlanId' => 99999,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertNotFound();
    expect(DB::table('reservation_records')->count())->toBe(0);
});

it('restricts rate plan creation to property admins but allows member reads', function (): void {
    [$hotel, $type, $guest] = phase24Hotel();
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->getJson($base.'/rate-plans')->assertUnauthorized();
    phase24Staff($hotel, 'front_desk', ['hotel:read']);
    $this->getJson($base.'/rate-plans')->assertOk();
    $this->postJson($base.'/rate-plans', [
        'roomTypeId' => $type->id, 'name' => 'BAR', 'nightlyMinor' => 250000,
    ])->assertForbidden();
    phase24Staff($hotel);
    phase24Rate($this, $hotel, $type);
    $this->postJson($base.'/rate-plans', [
        'roomTypeId' => $type->id, 'name' => 'Standard BAR', 'nightlyMinor' => 1,
    ])->assertUnprocessable();
});


it('keeps pre-Phase-24 unpriced idempotency hashes compatible', function (): void {
    [$hotel, $type, $guest] = phase24Hotel();
    phase24Staff($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $payload = [
        'idempotencyKey' => 'legacy-key-before-phase24',
        'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ];
    $id = $this->postJson($base.'/bookings', $payload)->assertCreated()->json('data.id');

    $legacyFingerprint = hash('sha256', json_encode([
        'guestId' => (int) $guest->id, 'roomTypeId' => (int) $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ], JSON_THROW_ON_ERROR));
    DB::table('reservation_booking_requests')->where('hotel_id', $hotel->id)
        ->where('idempotency_key', $payload['idempotencyKey'])
        ->update(['payload_sha256' => $legacyFingerprint]);

    $this->postJson($base.'/bookings', $payload)->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.idempotentReplay', true);
    expect(DB::table('reservation_records')->count())->toBe(1);
});

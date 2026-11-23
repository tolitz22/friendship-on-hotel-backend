<?php

declare(strict_types=1);

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function bookingTestHotel(int $capacity = 1): array
{
    $hotel = Hotel::create(['name' => 'Booking Test Hotel']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    for ($i = 1; $i <= $capacity; $i++) {
        Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => (string) (100 + $i)]);
    }
    $guest = $hotel->guests()->create(['first_name' => 'Hotel', 'last_name' => 'Guest']);

    return [$hotel, $type, $guest];
}

function bookingAdmin(Hotel $hotel, string $role = 'admin', array $abilities = ['hotel:read', 'hotel:write']): void
{
    $user = User::create([
        'name' => 'Booking Actor',
        'email' => 'booking-'.uniqid().'@example.test',
        'password' => 'TestPassword123!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    Sanctum::actingAs($user, $abilities);
}

function bookingPayload(RoomType $type, Guest $guest, string $key = 'request-0001'): array
{
    return [
        'idempotencyKey' => $key,
        'guestId' => $guest->id,
        'roomTypeId' => $type->id,
        'checkIn' => '2026-12-01',
        'checkOut' => '2026-12-03',
    ];
}

it('creates a confirmed booking and writes an audit event', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel();
    bookingAdmin($hotel);

    $this->postJson('/api/v1/hotels/'.$hotel->id.'/bookings', bookingPayload($type, $guest))
        ->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.guestId', (string) $guest->id)
        ->assertJsonPath('data.assignedRoomId', null);

    expect(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(1);
    expect(DB::table('reservation_booking_events')->where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('replays an identical idempotency key and rejects reuse with new data', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel();
    bookingAdmin($hotel);
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings';
    $payload = bookingPayload($type, $guest);

    $first = $this->postJson($path, $payload)->assertCreated()->json('data.id');
    $this->postJson($path, $payload)->assertOk()
        ->assertJsonPath('data.id', $first)
        ->assertJsonPath('data.idempotentReplay', true);

    $this->postJson($path, array_replace($payload, ['checkOut' => '2026-12-04']))
        ->assertStatus(409);

    expect(DB::table('reservation_records')->count())->toBe(1);
    expect(DB::table('reservation_booking_events')->count())->toBe(1);
});

it('prevents selling the same room type beyond nightly capacity', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel();
    bookingAdmin($hotel);
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings';

    $this->postJson($path, bookingPayload($type, $guest))->assertCreated();
    $this->postJson($path, array_replace(bookingPayload($type, $guest, 'request-0002'), [
        'checkIn' => '2026-12-02', 'checkOut' => '2026-12-04',
    ]))->assertStatus(409);

    $this->postJson($path, array_replace(bookingPayload($type, $guest, 'request-0003'), [
        'checkIn' => '2026-12-03', 'checkOut' => '2026-12-04',
    ]))->assertCreated();
    expect(DB::table('reservation_records')->count())->toBe(2);
});

it('releases room-type capacity after staff cancellation', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel();
    bookingAdmin($hotel);
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings';
    $id = $this->postJson($path, bookingPayload($type, $guest))->assertCreated()->json('data.id');

    $this->postJson($path.'/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson($path, bookingPayload($type, $guest, 'request-0002'))->assertCreated();
    $this->postJson($path.'/'.$id.'/cancel')->assertStatus(409);

    expect(DB::table('reservation_booking_events')->count())->toBe(3);
});

it('blocks unclassified legacy reservations until reconciled', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel();
    DB::table('reservation_records')->insert([
        'hotel_id' => $hotel->id,
        'room_type_id' => $type->id,
        'reference' => 'LEGACY-UNKNOWN',
        'check_in' => '2026-12-01',
        'check_out' => '2026-12-03',
        'lifecycle_state' => null,
    ]);
    bookingAdmin($hotel);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/bookings', bookingPayload($type, $guest))
        ->assertStatus(409);
});

it('enforces hotel guest and room type isolation', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel();
    [$other, $otherType, $otherGuest] = bookingTestHotel();
    bookingAdmin($hotel);
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings';

    $this->postJson($path, bookingPayload($otherType, $guest))->assertNotFound();
    $this->postJson($path, bookingPayload($type, $otherGuest))->assertNotFound();
    $this->postJson('/api/v1/hotels/'.$other->id.'/bookings', bookingPayload($otherType, $otherGuest))
        ->assertNotFound();
    expect(DB::table('reservation_records')->count())->toBe(0);
});

it('requires admin membership and write permission for booking mutations', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel();
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings';
    $this->postJson($path, bookingPayload($type, $guest))->assertUnauthorized();

    bookingAdmin($hotel, 'front_desk');
    $this->postJson($path, bookingPayload($type, $guest))->assertForbidden();

    bookingAdmin($hotel, 'admin', ['hotel:read']);
    $this->postJson($path, bookingPayload($type, $guest))->assertForbidden();
});

it('rejects zero-night stays and invalid inputs without inserting reservations', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel();
    bookingAdmin($hotel);
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings';
    $this->postJson($path, array_replace(bookingPayload($type, $guest), [
        'checkOut' => '2026-12-01',
    ]))->assertUnprocessable();
    $this->postJson($path, array_replace(bookingPayload($type, $guest), [
        'idempotencyKey' => 'short',
    ]))->assertUnprocessable();
    expect(DB::table('reservation_records')->count())->toBe(0);
});

it('quotes and extends a checked-in stay once and preserves its prior room nights', function (): void {
    [$hotel, $type, $guest] = bookingTestHotel(2);
    bookingAdmin($hotel);
    $actorId = DB::table('hotel_memberships')->where('hotel_id', $hotel->id)->value('user_id');
    $rate = DB::table('room_rate_plans')->insertGetId([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'created_by' => $actorId,
        'name' => 'Demo rate', 'nightly_minor' => 250000, 'currency' => 'PHP',
    ]);
    $today = now('Asia/Manila')->toImmutable()->startOfDay();
    $due = $today->addDay()->toDateString();
    $newDeparture = $today->addDays(3)->toDateString();
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings';
    $id = $this->postJson($path, array_replace(bookingPayload($type, $guest), [
        'checkIn' => $today->toDateString(), 'checkOut' => $due, 'ratePlanId' => $rate,
    ]))->assertCreated()->json('data.id');
    $room = DB::table('rooms')->where('hotel_id', $hotel->id)->orderBy('id')->value('id');
    DB::table('reservation_records')->where('id', $id)->update([
        'lifecycle_state' => 'checked_in', 'assigned_room_id' => $room,
    ]);
    $url = $path.'/'.$id.'/extend-stay';
    $input = ['expectedCheckOut' => $due, 'checkOut' => $newDeparture];
    $this->postJson($url, $input)->assertOk()->assertJsonPath('data.saved', false)
        ->assertJsonPath('data.subtotalMinor', 500000);
    expect(DB::table('folio_entries')->where('hotel_id', $hotel->id)->count())->toBe(1);

    DB::table('room_inventory_blocks')->insert([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'room_id' => $room,
        'check_in' => $due, 'check_out' => $newDeparture, 'reason' => 'Demo repair',
        'created_by' => $actorId,
    ]);
    $this->postJson($url, $input + ['expectedNightlyMinor' => 250000])->assertStatus(409);
    DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)->update(['is_active' => false]);

    $this->postJson($url, $input + ['expectedNightlyMinor' => 240000])->assertStatus(409);
    $this->postJson($url, $input + ['expectedNightlyMinor' => 250000])->assertOk()
        ->assertJsonPath('data.saved', true)->assertJsonPath('data.checkOut', $newDeparture);
    $this->postJson($url, $input + ['expectedNightlyMinor' => 250000])->assertStatus(409);
    expect(DB::table('folio_entries')->where('hotel_id', $hotel->id)->count())->toBe(3);
    expect(DB::table('reservation_records')->where('id', $id)->value('lifecycle_state'))->toBe('checked_in');
});

<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase23Setup(int $capacity = 1): array
{
    $hotel = Hotel::create(['name' => 'Phase23 Property', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $rooms = [];
    for ($i = 0; $i < $capacity; $i++) {
        $rooms[] = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P23-'.($i + 1)]);
    }
    $guest = $hotel->guests()->create(['first_name' => 'Taylor', 'last_name' => 'Guest']);
    return [$hotel, $type, $rooms, $guest];
}

function phase23Auth(Hotel $hotel, string $role = 'admin', array $abilities = ['hotel:read', 'hotel:write']): void
{
    $user = User::create(['name' => 'Front Desk Admin', 'email' => uniqid('p23-').'@example.test', 'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
    Sanctum::actingAs($user, $abilities);
}

function phase23Create($test, Hotel $hotel, RoomType $type, $guest, string $key, string $from, string $to): string
{
    return $test->postJson('/api/v1/hotels/'.$hotel->id.'/bookings', [
        'idempotencyKey' => $key, 'guestId' => $guest->id,
        'roomTypeId' => $type->id, 'checkIn' => $from, 'checkOut' => $to,
    ])->assertCreated()->json('data.id');
}

it('amends pre-arrival stay dates with capacity checks and a date audit', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 Asia/Manila'));
    [$hotel, $type, $rooms, $guest] = phase23Setup();
    phase23Auth($hotel);
    $id = phase23Create($this, $hotel, $type, $guest, 'amend-example-001', '2026-12-10', '2026-12-12');
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings/'.$id.'/dates';

    $this->patchJson($path, ['checkIn' => '2026-12-11', 'checkOut' => '2026-12-14'])
        ->assertOk()->assertJsonPath('data.checkOut', '2026-12-14');
    $this->patchJson($path, ['checkIn' => '2026-12-11', 'checkOut' => '2026-12-14'])
        ->assertOk()->assertJsonPath('data.unchanged', true);

    expect(DB::table('reservation_date_amendments')->count())->toBe(1);
    expect(DB::table('reservation_date_amendments')->first()->old_check_in)->toBe('2026-12-10');
    expect(DB::table('reservation_booking_events')->where('event_type', 'stay_amended')->count())->toBe(1);
});

it('rejects amendments that exceed capacity or collide with an assigned room', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 Asia/Manila'));
    [$hotel, $type, $rooms, $guest] = phase23Setup(2);
    phase23Auth($hotel);
    $first = phase23Create($this, $hotel, $type, $guest, 'amend-example-002', '2026-12-10', '2026-12-12');
    $second = phase23Create($this, $hotel, $type, $guest, 'amend-example-003', '2026-12-13', '2026-12-15');
    $base = '/api/v1/hotels/'.$hotel->id.'/bookings/';

    $this->patchJson($base.$first.'/assign-room', ['roomId' => $rooms[0]->id])->assertOk();
    $this->patchJson($base.$second.'/assign-room', ['roomId' => $rooms[0]->id])->assertOk();
    $this->patchJson($base.$first.'/dates', [
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-14',
    ])->assertStatus(409);
    expect(DB::table('reservation_records')->find($first)->check_out)->toBe('2026-12-12');

    $this->postJson('/api/v1/hotels/'.$hotel->id.'/inventory-blocks', [
        'roomId' => $rooms[1]->id, 'checkIn' => '2026-12-12',
        'checkOut' => '2026-12-13', 'reason' => 'Maintenance',
    ])->assertCreated();

    $this->patchJson($base.$first.'/dates', [
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-14',
    ])->assertStatus(409);
});

it('provides scoped arrival and departure manifests without turning mock data live', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10 10:00:00 Asia/Manila'));
    [$hotel, $type, $rooms, $guest] = phase23Setup();
    phase23Auth($hotel);
    $id = phase23Create($this, $hotel, $type, $guest, 'manifest-example-001', '2026-12-10', '2026-12-12');
    $base = '/api/v1/hotels/'.$hotel->id;

    $this->getJson($base.'/front-desk/manifest?date=2026-12-10&kind=arrivals')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.guestName', 'Taylor Guest')
        ->assertJsonPath('meta.determinesHousekeepingReadiness', false);
    $this->patchJson($base.'/bookings/'.$id.'/assign-room', ['roomId' => $rooms[0]->id])->assertOk();
    $this->postJson($base.'/bookings/'.$id.'/check-in')->assertOk();

    $this->getJson($base.'/front-desk/manifest?date=2026-12-10&kind=in_house')
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($base.'/front-desk/manifest?date=2026-12-12&kind=departures')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('creates exactly one housekeeping checkout task when a booking is checked out', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10 10:00:00 Asia/Manila'));
    [$hotel, $type, $rooms, $guest] = phase23Setup();
    phase23Auth($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $id = phase23Create($this, $hotel, $type, $guest, 'turnover-example-001', '2026-12-10', '2026-12-12');
    $this->patchJson($base.'/bookings/'.$id.'/assign-room', ['roomId' => $rooms[0]->id])->assertOk();
    $this->postJson($base.'/bookings/'.$id.'/check-in')->assertOk();

    $this->travelTo(new DateTimeImmutable('2026-12-12 10:00:00 Asia/Manila'));
    $this->postJson($base.'/bookings/'.$id.'/check-out')->assertOk();
    $this->postJson($base.'/bookings/'.$id.'/check-out')->assertStatus(409);

    expect(DB::table('reservation_turnover_links')->count())->toBe(1);
    expect(DB::table('housekeeping_tasks')->where('type', 'checkout_clean')->where('status', 'todo')->count())->toBe(1);
    expect(DB::table('housekeeping_task_events')->where('action', 'created')->count())->toBe(1);
});

it('rejects unauthorized amendments and scopes manifest to the member hotel', function (): void {
    [$hotel, $type, $rooms, $guest] = phase23Setup();
    [$other] = phase23Setup();
    $path = '/api/v1/hotels/'.$hotel->id;
    $this->getJson($path.'/front-desk/manifest?date=2026-12-10')->assertUnauthorized();
    phase23Auth($hotel, 'front_desk', ['hotel:read']);
    $this->getJson($path.'/front-desk/manifest?date=2026-12-10')->assertOk();
    $this->patchJson($path.'/bookings/1/dates', [
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertForbidden();
    $this->getJson('/api/v1/hotels/'.$other->id.'/front-desk/manifest?date=2026-12-10')
        ->assertNotFound();
});


it('records an expired unpriced booking as no-show only once', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 Asia/Manila'));
    [$hotel, $type, $rooms, $guest] = phase23Setup();
    phase23Auth($hotel);
    $id = phase23Create($this, $hotel, $type, $guest, 'no-show-example-001', '2026-11-21', '2026-11-22');
    $path = '/api/v1/hotels/'.$hotel->id.'/bookings/'.$id.'/no-show';
    $this->postJson($path)->assertStatus(409);
    $this->travelTo(new DateTimeImmutable('2026-11-23 10:00:00 Asia/Manila'));
    $this->postJson($path)->assertOk()->assertJsonPath('data.status', 'no_show');
    $this->postJson($path)->assertStatus(409);
    expect(DB::table('reservation_booking_events')->where('reservation_record_id', $id)->where('event_type', 'no_show')->count())->toBe(1);
});

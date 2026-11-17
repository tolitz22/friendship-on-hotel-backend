<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase57Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Night Audit Test', 'currency' => 'PHP', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '101']);
    $guest = $hotel->guests()->create(['first_name' => 'Audit', 'last_name' => 'Guest']);
    $manager = User::create([
        'name' => 'Audit Manager', 'email' => uniqid('phase57-',true).'@example.test',
        'password' => 'Password123456!',
    ]);
    $frontDesk = User::create([
        'name' => 'Audit Reception', 'email' => uniqid('phase57-',true).'@example.test',
        'password' => 'Password123456!',
    ]);
    foreach ([[$manager, 'admin'],[$frontDesk, 'front_desk']] as [$staff,$role]) {
        HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id,
            'role' => $role, 'is_active' => true]);
    }
    return compact('hotel','type','room','guest','manager','frontDesk');
}

it('reads already-posted room-night charges without adding a duplicate', function (): void {
    $this->travelTo(new DateTimeImmutable('2027-01-10T11:00:00+08:00'));
    $fx = phase57Fixture();
    $base = '/api/v1/hotels/'.$fx['hotel']->id;
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $rate = $this->postJson($base.'/rate-plans', [
        'roomTypeId' => $fx['type']->id, 'name' => 'BAR', 'nightlyMinor' => 180000,
    ])->assertCreated()->json('data.id');
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'audit57-'.uniqid(),
        'guestId' => $fx['guest']->id,
        'roomTypeId' => $fx['type']->id, 'ratePlanId' => $rate,
        'checkIn' => '2027-01-10', 'checkOut' => '2027-01-12',
    ])->assertCreated()->json('data.id');
    $count = DB::table('folio_entries')->count();

    $preview = $base.'/night-audit/preview?date=2027-01-10';
    $this->getJson($preview)->assertOk()
        ->assertJsonPath('data.roomCount', 1)
        ->assertJsonPath('data.scheduledRoomNights', 1)
        ->assertJsonPath('data.pricedRoomNights', 1)
        ->assertJsonPath('data.postedRoomChargesMinor', 180000)
        ->assertJsonPath('data.preflightClear', true)
        ->assertJsonPath('data.roomEntriesAlreadyPostedAtBooking', true)
        ->assertJsonPath('data.financialDayClosed', false)
        ->assertJsonPath('data.bankOrGatewayVerified', false);
    $this->getJson($preview)->assertOk()->assertJsonPath('data.postedRoomChargesMinor', 180000);
    expect(DB::table('folio_entries')->count())->toBe($count);
    expect(DB::table('folio_entries')->where('stay_date','2027-01-10')
        ->where('entry_type','room_night')->count())->toBe(1);
});

it('reports unpriced reservations and refuses future-date queries', function (): void {
    $this->travelTo(new DateTimeImmutable('2027-01-10T10:00:00+08:00'));
    $fx = phase57Fixture();
    $base = '/api/v1/hotels/'.$fx['hotel']->id;
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'audit57-unpriced-'.uniqid(),
        'guestId' => $fx['guest']->id,'roomTypeId' => $fx['type']->id,
        'checkIn' => '2027-01-10','checkOut' => '2027-01-11',
    ])->assertCreated()->json('data.id');
    $this->getJson($base.'/night-audit/preview?date=2027-01-10')->assertOk()
        ->assertJsonPath('data.preflightClear', false)
        ->assertJsonPath('data.unpricedBookings.0.bookingId', (string) $booking)
        ->assertJsonPath('data.postedRoomChargesMinor', 0);
    $this->getJson($base.'/night-audit/preview?date=2027-01-11')->assertUnprocessable();
    $this->getJson($base.'/night-audit/preview?date=invalid')->assertUnprocessable();
});

it('isolates manager financial preflight from reception and other hotels', function (): void {
    $this->travelTo(new DateTimeImmutable('2027-01-10T10:00:00+08:00'));
    $fx = phase57Fixture();
    $base = '/api/v1/hotels/'.$fx['hotel']->id;
    Sanctum::actingAs($fx['frontDesk'], ['hotel:read','booking:write']);
    $this->getJson($base.'/night-audit/preview?date=2027-01-10')->assertForbidden();
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $other = Hotel::create(['name' => 'Unrelated Night Audit']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/night-audit/preview?date=2027-01-10')
        ->assertNotFound();
});

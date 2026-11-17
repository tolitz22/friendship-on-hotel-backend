<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase50Staff(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => 'Phase 50 '.$role,
        'email' => uniqid('phase50-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id,
        'user_id' => $user->id,
        'role' => $role,
        'is_active' => true,
    ]);
    return $user;
}

it('lets active receptionists book with an actor audit but denies housekeeping booking writes', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 50 Reception']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P50-101']);
    $guest = $hotel->guests()->create(['first_name' => 'Phase', 'last_name' => 'Guest']);
    $url = '/api/v1/hotels/'.$hotel->id.'/bookings';
    $payload = [
        'idempotencyKey' => 'phase50-booking-'.uniqid(),
        'guestId' => $guest->id,
        'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10',
        'checkOut' => '2026-12-12',
    ];

    $housekeeper = phase50Staff($hotel, 'housekeeping');
    Sanctum::actingAs($housekeeper, ['hotel:read']);
    $this->postJson($url, $payload)->assertForbidden();

    $receptionist = phase50Staff($hotel, 'front_desk');
    Sanctum::actingAs($receptionist, ['hotel:read', 'booking:write']);
    $id = $this->postJson($url, $payload)->assertCreated()->json('data.id');
    expect(DB::table('reservation_booking_events')
        ->where('hotel_id', $hotel->id)
        ->where('reservation_record_id', $id)
        ->where('actor_id', $receptionist->id)
        ->where('event_type', 'created')->exists())->toBeTrue();
});

it('checks current hotel membership on every operational write including token and property boundaries', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 50 Primary']);
    $other = Hotel::create(['name' => 'Phase 50 Other']);
    $staff = phase50Staff($hotel, 'front_desk');
    $checkIn = '/api/v1/hotels/'.$hotel->id.'/bookings/999999/check-in';

    Sanctum::actingAs($staff, ['hotel:read', 'booking:write']);
    $this->postJson($checkIn)->assertNotFound();
    $this->postJson('/api/v1/hotels/'.$other->id.'/bookings/999999/check-in')->assertNotFound();

    HotelMembership::where('hotel_id', $hotel->id)->where('user_id', $staff->id)->update(['is_active' => false]);
    $this->postJson($checkIn)->assertNotFound();

    HotelMembership::where('hotel_id', $hotel->id)->where('user_id', $staff->id)->update(['is_active' => true]);
    Sanctum::actingAs($staff, ['hotel:read']);
    $this->postJson($checkIn)->assertForbidden();
});

it('keeps refunds cancellations and direct payment writes administrator-only', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 50 Finance Gate']);
    $staff = phase50Staff($hotel, 'front_desk');
    Sanctum::actingAs($staff, ['hotel:read']);
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->postJson($base.'/bookings/999999/cancel')->assertForbidden();
    $this->postJson($base.'/bookings/999999/refund-requests')->assertForbidden();
    $this->postJson($base.'/bookings/999999/payment-records', [])->assertForbidden();
});

it('does not let an admin token without write ability bypass permissions', function (): void {
    $hotel = Hotel::create(['name' => 'Phase 50 Token Gate']);
    $admin = phase50Staff($hotel, 'admin');
    Sanctum::actingAs($admin, ['hotel:read']);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/bookings/999999/check-out')
        ->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase22Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Phase 22 Test Hotel']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '101']);
    $otherRoom = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '102']);
    $guest = $hotel->guests()->create(['first_name' => 'Phase', 'last_name' => 'Guest']);
    return [$hotel, $type, $room, $otherRoom, $guest];
}

function phase22Admin(Hotel $hotel, string $role = 'admin', array $abilities = ['hotel:read', 'hotel:write']): void
{
    $user = User::create([
        'name' => 'Operations Staff',
        'email' => 'phase22-'.uniqid().'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    Sanctum::actingAs($user, $abilities);
}

function phase22Booking(RoomType $type, int $guestId, string $key): array
{
    return [
        'idempotencyKey' => $key, 'guestId' => $guestId, 'roomTypeId' => $type->id,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ];
}

it('requires room assignment for check-in and audits both stay transitions', function (): void {
    [$hotel, $type, $room, $otherRoom, $guest] = phase22Fixture();
    phase22Admin($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $id = $this->postJson($base.'/bookings', phase22Booking($type, $guest->id, 'phase22-key-0001'))
        ->assertCreated()->json('data.id');

    $this->postJson($base.'/bookings/'.$id.'/check-out')->assertStatus(409);
    $this->postJson($base.'/bookings/'.$id.'/check-in')->assertStatus(409);
    $this->travelTo(new \DateTimeImmutable('2026-12-10 12:00:00 Asia/Manila'));
    $this->patchJson($base.'/bookings/'.$id.'/assign-room', ['roomId' => $room->id])->assertOk();
    $this->postJson($base.'/bookings/'.$id.'/check-in')
        ->assertOk()->assertJsonPath('data.status', 'checked_in')
        ->assertJsonPath('data.assignedRoomId', (string) $room->id);
    $this->postJson($base.'/bookings/'.$id.'/check-in')->assertStatus(409);
    $this->postJson($base.'/bookings/'.$id.'/check-out')->assertStatus(409);
    $this->travelTo(new \DateTimeImmutable('2026-12-12 12:00:00 Asia/Manila'));
    $this->postJson($base.'/bookings/'.$id.'/check-out')
        ->assertOk()->assertJsonPath('data.status', 'checked_out');
    $this->postJson($base.'/bookings/'.$id.'/check-out')->assertStatus(409);

    expect(DB::table('reservation_booking_events')->where('event_type', 'check_in')->count())->toBe(1);
    expect(DB::table('reservation_booking_events')->where('event_type', 'check_out')->count())->toBe(1);
    expect(DB::table('reservation_records')->find($id)->lifecycle_state)->toBe('checked_out');
});

it('rejects cancelled stay check-in and cross-hotel transitions', function (): void {
    [$hotel, $type, $room, $otherRoom, $guest] = phase22Fixture();
    [$outside] = phase22Fixture();
    phase22Admin($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $id = $this->postJson($base.'/bookings', phase22Booking($type, $guest->id, 'phase22-key-0002'))
        ->assertCreated()->json('data.id');
    $this->postJson($base.'/bookings/'.$id.'/cancel')->assertOk();
    $this->postJson($base.'/bookings/'.$id.'/check-in')->assertStatus(409);
    $this->postJson('/api/v1/hotels/'.$outside->id.'/bookings/'.$id.'/check-in')->assertNotFound();
});

it('lists scoped active and released blocks for the administrator', function (): void {
    [$hotel, $type, $room, $otherRoom, $guest] = phase22Fixture();
    phase22Admin($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $body = [
        'roomId' => $room->id, 'checkIn' => '2026-12-10',
        'checkOut' => '2026-12-12', 'reason' => 'Scheduled maintenance',
    ];
    $id = $this->postJson($base.'/inventory-blocks', $body)->assertCreated()->json('data.id');
    $this->getJson($base.'/inventory-blocks?active=1')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.reason', 'Scheduled maintenance');
    $this->postJson($base.'/inventory-blocks/'.$id.'/release')->assertOk();
    $this->getJson($base.'/inventory-blocks?active=1')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($base.'/inventory-blocks?active=0')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.active', false);
});

it('blocks room type reassignment when the physical room has inventory history', function (): void {
    [$hotel, $type, $room, $otherRoom, $guest] = phase22Fixture();
    $destination = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Suite', 'max_occupancy' => 4]);
    phase22Admin($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $id = $this->postJson($base.'/inventory-blocks', [
        'roomId' => $room->id, 'checkIn' => '2026-12-10',
        'checkOut' => '2026-12-12', 'reason' => 'Room offline',
    ])->assertCreated()->json('data.id');
    $this->postJson($base.'/inventory-blocks/'.$id.'/release')->assertOk();

    $this->patchJson($base.'/rooms/'.$room->id, ['roomTypeId' => $destination->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['roomTypeId']);
    expect((int) $room->fresh()->room_type_id)->toBe((int) $type->id);
});

it('keeps stay transitions and block listings administrative', function (): void {
    [$hotel, $type, $room, $otherRoom, $guest] = phase22Fixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->getJson($base.'/inventory-blocks')->assertUnauthorized();
    phase22Admin($hotel, 'front_desk', ['hotel:read']);
    $this->getJson($base.'/inventory-blocks')->assertForbidden();
    $this->postJson($base.'/bookings/1/check-in')->assertForbidden();
    phase22Admin($hotel, 'admin', ['hotel:read']);
    $this->postJson($base.'/bookings/1/check-out')->assertForbidden();
});

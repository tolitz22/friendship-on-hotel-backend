<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function inventoryFixture(int $roomCount = 2): array
{
    $hotel = Hotel::create(['name' => 'Phase 21 Hotel']);
    $type = RoomType::create([
        'hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2,
    ]);
    $rooms = [];
    for ($i = 1; $i <= $roomCount; $i++) {
        $rooms[] = Room::create([
            'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
            'number' => (string) (500 + $i),
        ]);
    }
    $guest = $hotel->guests()->create(['first_name' => 'Inventory', 'last_name' => 'Guest']);
    return [$hotel, $type, $rooms, $guest];
}

function inventoryActor(Hotel $hotel, string $role = 'admin', array $abilities = ['hotel:read', 'hotel:write']): void
{
    $user = User::create([
        'name' => 'Phase21 Staff', 'email' => 'inventory-'.uniqid().'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    Sanctum::actingAs($user, $abilities);
}

function inventoryBookingPayload(RoomType $type, int $guestId, string $key): array
{
    return [
        'idempotencyKey' => $key, 'guestId' => $guestId,
        'roomTypeId' => $type->id, 'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ];
}

function inventoryBlockPayload(Room $room, string $from = '2026-12-10', string $to = '2026-12-12'): array
{
    return ['roomId' => $room->id, 'checkIn' => $from, 'checkOut' => $to, 'reason' => 'Air conditioner repair'];
}

it('returns per-night inventory based on bookings and dated room blocks', function (): void {
    [$hotel, $type, $rooms, $guest] = inventoryFixture();
    inventoryActor($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $query = '?roomTypeId='.$type->id.'&checkIn=2026-12-10&checkOut=2026-12-12';
    $this->getJson($base.'/room-type-availability'.$query)
        ->assertOk()->assertJsonPath('data.nights.0.remaining', 2)
        ->assertJsonPath('data.canBook', true)
        ->assertJsonPath('meta.housekeepingReadinessVerified', false);

    $this->postJson($base.'/inventory-blocks', inventoryBlockPayload($rooms[0]))->assertCreated();
    $this->getJson($base.'/room-type-availability'.$query)
        ->assertOk()->assertJsonPath('data.nights.0.blocked', 1)
        ->assertJsonPath('data.nights.0.remaining', 1);
    $this->postJson($base.'/bookings', inventoryBookingPayload($type, $guest->id, 'inventory-test-001'))
        ->assertCreated();

    $this->getJson($base.'/room-type-availability'.$query)
        ->assertOk()->assertJsonPath('data.nights.0.reserved', 1)
        ->assertJsonPath('data.nights.0.remaining', 0)
        ->assertJsonPath('data.canBook', false);
    $this->postJson($base.'/bookings', inventoryBookingPayload($type, $guest->id, 'inventory-test-002'))
        ->assertStatus(409);
});

it('refuses blocks that would overcommit reservations and allows release', function (): void {
    [$hotel, $type, $rooms, $guest] = inventoryFixture(1);
    inventoryActor($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;

    $this->postJson($base.'/bookings', inventoryBookingPayload($type, $guest->id, 'inventory-test-003'))
        ->assertCreated();
    $this->postJson($base.'/inventory-blocks', inventoryBlockPayload($rooms[0]))
        ->assertStatus(409);
    expect(DB::table('room_inventory_blocks')->count())->toBe(0);

    // Block an adjacent period, then release it.
    $blockId = $this->postJson($base.'/inventory-blocks',
        inventoryBlockPayload($rooms[0], '2026-12-12', '2026-12-13'))
        ->assertCreated()->json('data.id');
    $this->postJson($base.'/inventory-blocks/'.$blockId.'/release')
        ->assertOk()->assertJsonPath('data.active', false);
    $this->postJson($base.'/inventory-blocks/'.$blockId.'/release')->assertStatus(409);
});

it('assigns only a suitable unblocked and unoccupied room within the hotel', function (): void {
    [$hotel, $type, $rooms, $guest] = inventoryFixture(2);
    inventoryActor($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $bookingId = $this->postJson($base.'/bookings', inventoryBookingPayload($type, $guest->id, 'inventory-test-004'))
        ->assertCreated()->json('data.id');
    $this->postJson($base.'/inventory-blocks', inventoryBlockPayload($rooms[0]))->assertCreated();

    $this->patchJson($base.'/bookings/'.$bookingId.'/assign-room',
        ['roomId' => $rooms[0]->id])->assertStatus(409);
    $this->patchJson($base.'/bookings/'.$bookingId.'/assign-room',
        ['roomId' => $rooms[1]->id])->assertOk()
        ->assertJsonPath('data.roomId', (string) $rooms[1]->id);

    $this->patchJson($base.'/bookings/'.$bookingId.'/assign-room',
        ['roomId' => $rooms[1]->id])->assertOk()
        ->assertJsonPath('data.unchanged', true);
    expect(DB::table('reservation_booking_events')->where('event_type', 'room_assigned')->count())->toBe(1);
});

it('prevents assignment collisions with other confirmed reservations', function (): void {
    [$hotel, $type, $rooms, $guest] = inventoryFixture(2);
    inventoryActor($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;
    $first = $this->postJson($base.'/bookings', inventoryBookingPayload($type, $guest->id, 'inventory-test-005'))
        ->assertCreated()->json('data.id');
    $second = $this->postJson($base.'/bookings', inventoryBookingPayload($type, $guest->id, 'inventory-test-006'))
        ->assertCreated()->json('data.id');

    $this->patchJson($base.'/bookings/'.$first.'/assign-room', ['roomId' => $rooms[0]->id])->assertOk();
    $this->patchJson($base.'/bookings/'.$second.'/assign-room', ['roomId' => $rooms[0]->id])->assertStatus(409);
    $this->patchJson($base.'/bookings/'.$second.'/assign-room', ['roomId' => $rooms[1]->id])->assertOk();
});

it('isolates inventory routes by property and requires administrative writes', function (): void {
    [$hotel, $type, $rooms] = inventoryFixture();
    [$other, $otherType, $otherRooms] = inventoryFixture();
    $base = '/api/v1/hotels/'.$hotel->id;

    $this->getJson($base.'/room-type-availability')->assertUnauthorized();
    inventoryActor($hotel, 'front_desk', ['hotel:read']);
    $this->getJson($base.'/room-type-availability?roomTypeId='.$type->id.
        '&checkIn=2026-12-10&checkOut=2026-12-12')->assertOk();
    $this->postJson($base.'/inventory-blocks', inventoryBlockPayload($rooms[0]))->assertForbidden();

    inventoryActor($hotel);
    $this->postJson($base.'/inventory-blocks', inventoryBlockPayload($otherRooms[0]))->assertNotFound();
    $this->getJson($base.'/room-type-availability?roomTypeId='.$otherType->id.
        '&checkIn=2026-12-10&checkOut=2026-12-12')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$other->id.'/room-type-availability?roomTypeId='.$otherType->id.
        '&checkIn=2026-12-10&checkOut=2026-12-12')->assertNotFound();
});

it('rejects invalid date windows and empty block reasons', function (): void {
    [$hotel, $type, $rooms] = inventoryFixture();
    inventoryActor($hotel);
    $base = '/api/v1/hotels/'.$hotel->id;

    $this->getJson($base.'/room-type-availability?roomTypeId='.$type->id.
        '&checkIn=2026-12-10&checkOut=2026-12-10')->assertUnprocessable();
    $this->postJson($base.'/inventory-blocks', array_replace(
        inventoryBlockPayload($rooms[0]), ['reason' => '   ']
    ))->assertUnprocessable();
});

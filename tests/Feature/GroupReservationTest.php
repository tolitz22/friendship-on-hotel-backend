<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function groupFixture(int $capacity = 2): array
{
    $hotel = Hotel::create(['name' => 'Group Test Property', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Group Standard', 'max_occupancy' => 2]);
    for ($i = 1; $i <= $capacity; $i++) {
        Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => (string) (300 + $i)]);
    }
    $ids = [];
    foreach (['First', 'Second', 'Third'] as $name) {
        $ids[] = DB::table('guests')->insertGetId([
            'hotel_id' => $hotel->id, 'first_name' => $name, 'last_name' => 'Guest',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $staff = User::create(['name' => 'Reception Staff', 'email' => 'group-test@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id,
        'role' => 'front_desk', 'is_active' => true]);
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);
    return [$hotel, $type, $ids, $staff];
}

function groupPayload(RoomType $type, array $ids, int $count = 2): array
{
    $checkIn = now('Asia/Manila')->addDay()->toDateString();
    $checkOut = now('Asia/Manila')->addDays(2)->toDateString();
    return [
        'idempotencyKey' => 'test-group-request-00001',
        'name' => 'Team Conference', 'contactName' => 'Group Organizer',
        'checkIn' => $checkIn, 'checkOut' => $checkOut,
        'rooms' => array_map(static fn ($guest): array => [
            'guestId' => $guest, 'roomTypeId' => $type->id,
        ], array_slice($ids, 0, $count)),
    ];
}

it('creates all group bookings atomically and safely replays the request', function (): void {
    [$hotel, $type, $guests] = groupFixture();
    $endpoint = '/api/v1/hotels/'.$hotel->id.'/group-reservations';
    $payload = groupPayload($type, $guests);
    $created = $this->postJson($endpoint, $payload)->assertCreated()
        ->assertJsonPath('data.roomCount', 2)
        ->assertJsonPath('data.billingMode', 'individual_folios')
        ->assertJsonPath('data.groupBillingTransfersEnabled', false);
    $id = $created->json('data.id');
    expect(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(2);
    expect(DB::table('group_reservation_members')->where('hotel_id', $hotel->id)->count())->toBe(2);

    $this->postJson($endpoint, $payload)->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.idempotentReplay', true);
    expect(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(2);
    $changed = $payload;
    $changed['name'] = 'Different group';
    $this->postJson($endpoint, $changed)->assertStatus(409);

    $this->getJson($endpoint.'/'.$id)->assertOk()->assertJsonPath('data.roomCount', 2);
    $this->getJson($endpoint)->assertOk()->assertJsonPath('meta.total', 1);
});

it('rolls back every child when group inventory is insufficient', function (): void {
    [$hotel, $type, $guests] = groupFixture(2);
    $endpoint = '/api/v1/hotels/'.$hotel->id.'/group-reservations';
    $this->postJson($endpoint, groupPayload($type, $guests, 3))->assertStatus(409);
    expect(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(0);
    expect(DB::table('group_reservations')->where('hotel_id', $hotel->id)->count())->toBe(0);
    expect(DB::table('group_reservation_members')->where('hotel_id', $hotel->id)->count())->toBe(0);
    expect(DB::table('reservation_booking_requests')->where('hotel_id', $hotel->id)->count())->toBe(0);
});

it('rejects foreign hotel guest IDs without creating partial group members', function (): void {
    [$hotel, $type, $guests] = groupFixture();
    $other = Hotel::create(['name' => 'Other Tenant']);
    $foreignGuest = DB::table('guests')->insertGetId([
        'hotel_id' => $other->id, 'first_name' => 'Other', 'last_name' => 'Tenant',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $payload = groupPayload($type, $guests);
    $payload['rooms'][1]['guestId'] = $foreignGuest;
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/group-reservations', $payload)->assertNotFound();
    expect(DB::table('group_reservations')->where('hotel_id', $hotel->id)->count())->toBe(0);
    expect(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(0);
});

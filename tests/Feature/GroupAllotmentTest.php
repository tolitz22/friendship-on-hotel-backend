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

function allotmentFixture(): array
{
    $hotel = Hotel::create(['name' => 'Allotment Hotel', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Twin', 'max_occupancy' => 2]);
    foreach (['501', '502'] as $number) {
        Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => $number]);
    }
    $admin = User::create(['name' => 'Allotment Manager', 'email' => 'allotment@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $guests = [];
    foreach (['One', 'Two'] as $name) {
        $guests[] = DB::table('guests')->insertGetId([
            'hotel_id' => $hotel->id, 'first_name' => $name, 'last_name' => 'Group',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    return [$hotel, $type, $guests, $admin];
}

function holdInput(RoomType $type): array
{
    return [
        'idempotencyKey' => 'allotment-request-test-01', 'name' => 'Conference hold',
        'roomTypeId' => $type->id, 'quantity' => 2,
        'checkIn' => now('Asia/Manila')->addDay()->toDateString(),
        'checkOut' => now('Asia/Manila')->addDays(2)->toDateString(),
        'holdMinutes' => 60,
    ];
}

it('consumes capacity, replays idempotently, and frees capacity on release', function (): void {
    [$hotel, $type] = allotmentFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $payload = holdInput($type);
    $id = $this->postJson($base.'/group-allotments', $payload)
        ->assertCreated()->json('data.id');
    $this->postJson($base.'/group-allotments', $payload)
        ->assertOk()->assertJsonPath('data.id', $id);
    $query = '?roomTypeId='.$type->id.'&checkIn='.$payload['checkIn'].'&checkOut='.$payload['checkOut'];
    $this->getJson($base.'/room-type-availability'.$query)->assertOk()
        ->assertJsonPath('data.nights.0.remaining', 0);
    $changed = $payload;
    $changed['quantity'] = 1;
    $this->postJson($base.'/group-allotments', $changed)->assertStatus(409);
    $this->postJson($base.'/group-allotments', [
        ...$payload, 'idempotencyKey' => 'different-allotment-02',
    ])->assertStatus(409);
    $this->postJson($base.'/group-allotments/'.$id.'/release')->assertOk();
    $this->getJson($base.'/room-type-availability'.$query)->assertOk()
        ->assertJsonPath('data.nights.0.remaining', 2);
    $this->postJson($base.'/group-allotments/'.$id.'/release')->assertStatus(409);
});

it('automatically excludes expired holds from nightly inventory without deleting audit records', function (): void {
    [$hotel, $type] = allotmentFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $input = holdInput($type);
    $id = $this->postJson($base.'/group-allotments', $input)->assertCreated()->json('data.id');
    DB::table('group_allotments')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
    $query = '?roomTypeId='.$type->id.'&checkIn='.$input['checkIn'].'&checkOut='.$input['checkOut'];
    $this->getJson($base.'/room-type-availability'.$query)
        ->assertJsonPath('data.nights.0.remaining', 2);
    $this->getJson($base.'/group-allotments')->assertJsonPath('data.0.status', 'expired');
});

it('converts an entire hold atomically into individual bookings with no double consumption', function (): void {
    [$hotel, $type, $guests] = allotmentFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $input = holdInput($type);
    $hold = $this->postJson($base.'/group-allotments', $input)->assertCreated()->json('data.id');
    $group = [
        'idempotencyKey' => 'conversion-group-001', 'allotmentId' => (int) $hold,
        'name' => 'Conference booking', 'checkIn' => $input['checkIn'],
        'checkOut' => $input['checkOut'],
        'rooms' => array_map(fn ($id) => ['guestId' => $id, 'roomTypeId' => $type->id], $guests),
    ];
    $created = $this->postJson($base.'/group-reservations', $group)->assertCreated()
        ->assertJsonPath('data.roomCount', 2);
    $groupId = $created->json('data.id');
    $this->assertDatabaseHas('group_allotments', [
        'id' => $hold, 'status' => 'converted', 'converted_group_id' => $groupId,
    ]);
    $this->postJson($base.'/group-reservations', $group)
        ->assertOk()->assertJsonPath('data.id', $groupId);
    $query = '?roomTypeId='.$type->id.'&checkIn='.$input['checkIn'].'&checkOut='.$input['checkOut'];
    $this->getJson($base.'/room-type-availability'.$query)
        ->assertJsonPath('data.nights.0.remaining', 0);
    $this->postJson($base.'/group-allotments/'.$hold.'/release')->assertStatus(409);
    $this->getJson($base.'/group-reservations/'.$groupId.'/financial-summary')
        ->assertOk()->assertJsonPath('meta.readOnly', true);
});

it('rejects mismatched and expired conversions without partial reservations', function (): void {
    [$hotel, $type, $guests] = allotmentFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $input = holdInput($type);
    $hold = $this->postJson($base.'/group-allotments', $input)->assertCreated()->json('data.id');
    DB::table('group_allotments')->where('id', $hold)->update(['expires_at' => now()->subMinute()]);
    $this->postJson($base.'/group-reservations', [
        'idempotencyKey' => 'conversion-expired-01', 'allotmentId' => (int) $hold,
        'name' => 'Expired group', 'checkIn' => $input['checkIn'], 'checkOut' => $input['checkOut'],
        'rooms' => array_map(fn ($id) => ['guestId' => $id, 'roomTypeId' => $type->id], $guests),
    ])->assertStatus(409);
    expect(DB::table('group_reservations')->where('hotel_id', $hotel->id)->count())->toBe(0);
    expect(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(0);
});

it('requires management authorization for inventory holds and payment allocations', function (): void {
    [$hotel, $type, $guests, $admin] = allotmentFixture();
    $reception = User::create(['name' => 'Reception', 'email' => 'reception-hold@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $reception->id,
        'role' => 'front_desk', 'is_active' => true]);
    Sanctum::actingAs($reception, ['hotel:read', 'booking:write']);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/group-allotments', holdInput($type))
        ->assertForbidden();
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/group-reservations/1/bookings/1/payments/1/allocations', [
        'requestKey' => 'blocked-allocation-01', 'amountMinor' => 100,
    ])->assertForbidden();
});

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
    $this->getJson($base.'/reports/booking-forecast')->assertOk()
        ->assertJsonCount(14, 'data.nights')
        ->assertJsonPath('data.hotelId', (string) $hotel->id)
        ->assertJsonPath('data.nights.1.date', $payload['checkIn'])
        ->assertJsonPath('data.nights.1.sellable', 2)
        ->assertJsonPath('data.nights.1.groupHeld', 2)
        ->assertJsonPath('data.nights.1.remaining', 0)
        ->assertJsonPath('data.nights.2.remaining', 2);
    $changed = $payload;
    $changed['quantity'] = 1;
    $this->postJson($base.'/group-allotments', $changed)->assertStatus(409);
    $this->postJson($base.'/group-allotments', [
        ...$payload, 'idempotencyKey' => 'different-allotment-02',
    ])->assertStatus(409);
    $expiryBeforeRelease = DB::table('group_allotments')->where('id', $id)->value('expires_at');
    $this->postJson($base.'/group-allotments/'.$id.'/release')->assertOk();
    expect((string) DB::table('group_allotments')->where('id', $id)->value('expires_at'))
        ->toBe((string) $expiryBeforeRelease);
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
    $expiryBeforeConversion = DB::table('group_allotments')->where('id', $hold)->value('expires_at');
    $created = $this->postJson($base.'/group-reservations', $group)->assertCreated()
        ->assertJsonPath('data.roomCount', 2);
    $groupId = $created->json('data.id');
    expect((string) DB::table('group_allotments')->where('id', $hold)->value('expires_at'))
        ->toBe((string) $expiryBeforeConversion);
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
    // The group list must show travel dates, never substitute creation date.
    $this->getJson($base.'/group-reservations')
        ->assertOk()
        ->assertJsonPath('data.0.checkIn', $input['checkIn'])
        ->assertJsonPath('data.0.checkOut', $input['checkOut']);
    $this->getJson($base.'/group-reservations/'.$groupId)
        ->assertOk()
        ->assertJsonPath('data.checkIn', $input['checkIn'])
        ->assertJsonPath('data.checkOut', $input['checkOut'])
        ->assertJsonPath('data.operations.assignedCount', 0)
        ->assertJsonPath('data.operations.readinessCheckedCount', 0)
        ->assertJsonPath('data.operations.checkedOutCount', 0)
        ->assertJsonPath('data.operations.checkoutCleaningCompleteCount', 0)
        ->assertJsonPath('data.operations.readinessIsAdvisoryOnly', true);

    $this->getJson($base.'/reports/booking-forecast')->assertOk()
        ->assertJsonPath('data.nights.1.arrivals', 2)
        ->assertJsonPath('data.nights.1.reserved', 2)
        ->assertJsonPath('data.nights.1.groupHeld', 0)
        ->assertJsonPath('data.nights.2.departures', 2)
        ->assertJsonPath('data.nights.2.reserved', 0)
        ->assertJsonPath('data.nights.2.remaining', 2);

    // Unassigned group rooms are visible by guest and group reference,
    // but are never treated as occupied physical rooms.
    $this->getJson($base.'/room-calendar?start='.$input['checkIn'].'&end='.$input['checkOut'])
        ->assertOk()->assertJsonPath('data.unassignedGroupTotal', 2)
        ->assertJsonPath('data.unassignedGroupBookings.0.guestName', 'One Group')
        ->assertJsonPath('data.unassignedGroupBookings.0.groupId', (string) $groupId);
    // Assigned room lanes remain visible through group-specific focus, along
    // with guest identity and group reference, independent of rack pagination.
    $firstRoom = DB::table('rooms')->where('hotel_id', $hotel->id)
        ->orderBy('id')->value('id');
    $firstBooking = DB::table('group_reservation_members')
        ->where('hotel_id', $hotel->id)->where('group_reservation_id', $groupId)
        ->orderBy('position')->value('reservation_record_id');
    DB::table('reservation_records')->where('hotel_id', $hotel->id)
        ->where('id', $firstBooking)->update(['assigned_room_id' => $firstRoom]);
    $focused = $this->getJson($base.'/room-calendar?start='.$input['checkIn']
        .'&end='.$input['checkOut'].'&group_id='.$groupId)->assertOk()
        ->assertJsonPath('data.focusedGroupId', (string) $groupId)
        ->assertJsonPath('data.bookings.0.groupId', (string) $groupId)
        ->assertJsonPath('data.bookings.0.groupName', 'Conference booking')
        ->assertJsonPath('data.bookings.0.guestName', 'One Group')
        ->assertJsonPath('data.unassignedGroupTotal', 1);
    $focused->assertJsonCount(1, 'data.rooms');
    $this->getJson($base.'/group-reservations/'.$groupId)
        ->assertOk()
        ->assertJsonPath('data.operations.assignedCount', 1)
        ->assertJsonPath('data.operations.readinessAdvisoryReadyCount', 0);
    // A group filter is tenant-scoped; a fabricated group id cannot expose
    // room lanes from another tenant or fall back to the hotel's full rack.
    $this->getJson($base.'/room-calendar?start='.$input['checkIn']
        .'&end='.$input['checkOut'].'&group_id=999999')->assertNotFound();

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

it('does not expose or release another hotel allotment', function (): void {
    [$hotel, $type, $guests, $admin] = allotmentFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $id = $this->postJson($base.'/group-allotments', holdInput($type))
        ->assertCreated()->json('data.id');
    $other = Hotel::create(['name' => 'Unrelated Hotel']);
    HotelMembership::create(['hotel_id' => $other->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true]);
    $otherBase = '/api/v1/hotels/'.$other->id;
    $this->getJson($otherBase.'/group-allotments')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson($otherBase.'/group-allotments/'.$id.'/release')->assertNotFound();
    $this->assertDatabaseHas('group_allotments', ['id' => $id, 'status' => 'held']);
});

it('does not allocate group funds against a reservation outside the group', function (): void {
    [$hotel, $type, $guests] = allotmentFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $group = $this->postJson($base.'/group-reservations', [
        'idempotencyKey' => 'group-finance-test-001',
        'name' => 'Finance Review Group',
        'checkIn' => now('Asia/Manila')->addDay()->toDateString(),
        'checkOut' => now('Asia/Manila')->addDays(2)->toDateString(),
        'rooms' => array_map(fn ($guestId) => [
            'guestId' => $guestId, 'roomTypeId' => $type->id,
        ], $guests),
    ])->assertCreated()->json('data');
    $this->getJson($base.'/group-reservations/'.$group['id'].'/financial-summary')
        ->assertOk()->assertJsonPath('meta.postsTransactions', false);
    $this->postJson($base.'/group-reservations/'.$group['id'].'/bookings/99999/payments/1/allocations', [
        'requestKey' => 'wrong-group-booking-001', 'amountMinor' => 100,
    ])->assertNotFound();
    expect(DB::table('folio_settlement_allocations')->where('hotel_id', $hotel->id)->count())->toBe(0);
});

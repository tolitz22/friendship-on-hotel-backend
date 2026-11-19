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
    Sanctum::actingAs($staff, ['hotel:read', 'booking:write']);
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

    $this->getJson($endpoint.'/'.$id)->assertOk()
        ->assertJsonPath('data.roomCount', 2)
        ->assertJsonPath('data.createdByName', 'Reception Staff')
        ->assertJsonCount(2, 'data.activity')
        ->assertJsonPath('data.activity.0.action', 'created')
        ->assertJsonPath('data.activity.0.actorName', 'Reception Staff')
        ->assertJsonPath('data.activityHasMore', false)
        ->assertJsonPath('data.operations.checkoutCleaningUnassignedCount', 0);
    $this->getJson($endpoint)->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.summary.totalGroups', 1)
        ->assertJsonPath('meta.summary.upcomingArrivals', 1)
        ->assertJsonPath('meta.summary.inHouseGroups', 0)
        ->assertJsonPath('meta.summary.attentionGroups', 0);
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

it('requires receptionist booking-write authority before creating a group', function (): void {
    [$hotel, $type, $guests, $staff] = groupFixture();
    Sanctum::actingAs($staff, ['hotel:read']);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/group-reservations',
        groupPayload($type, $guests))->assertForbidden();
    expect(DB::table('group_reservations')->where('hotel_id', $hotel->id)->count())->toBe(0);
    expect(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(0);
});

it('derives group lifecycle from every linked room and filters with hotel-scoped search', function (): void {
    [$hotel, $type, $guests] = groupFixture();
    $endpoint = '/api/v1/hotels/'.$hotel->id.'/group-reservations';
    $created = $this->postJson($endpoint, groupPayload($type, $guests))->assertCreated();
    $id = $created->json('data.id');
    $bookingIds = DB::table('group_reservation_members')
        ->where('hotel_id', $hotel->id)->where('group_reservation_id', (int) $id)
        ->orderBy('position')->pluck('reservation_record_id')->all();

    $this->getJson($endpoint.'?status=confirmed&search=Team')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'confirmed')
        ->assertJsonPath('data.0.statusCounts.confirmed', 2);
    $this->getJson($endpoint.'?search=No-Such-Group')->assertOk()
        ->assertJsonPath('meta.total', 0);
    $this->getJson($endpoint.'?status=checked_out')->assertOk()
        ->assertJsonPath('meta.total', 0);

    DB::table('reservation_records')->where('id', $bookingIds[0])
        ->update(['lifecycle_state' => 'checked_in']);
    $this->getJson($endpoint.'?status=partially_checked_in')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'partially_checked_in')
        ->assertJsonPath('data.0.statusCounts.checkedIn', 1);
    $this->getJson($endpoint)->assertOk()
        ->assertJsonPath('meta.summary.inHouseGroups', 1)
        ->assertJsonPath('meta.summary.attentionGroups', 1);

    DB::table('reservation_records')->where('id', $bookingIds[1])
        ->update(['lifecycle_state' => 'checked_in']);
    $this->getJson($endpoint.'?status=fully_checked_in')->assertOk()
        ->assertJsonPath('data.0.status', 'fully_checked_in');

    DB::table('reservation_records')->where('id', $bookingIds[0])
        ->update(['lifecycle_state' => 'checked_out']);
    $this->getJson($endpoint.'?status=partially_checked_out')->assertOk()
        ->assertJsonPath('data.0.status', 'partially_checked_out');

    DB::table('reservation_records')->where('id', $bookingIds[1])
        ->update(['lifecycle_state' => 'checked_out']);
    $this->getJson($endpoint.'?status=checked_out')->assertOk()
        ->assertJsonPath('data.0.status', 'checked_out')
        ->assertJsonPath('data.0.statusCounts.checkedOut', 2);
    $this->getJson($endpoint.'?status=partially_checked_out')->assertOk()
        ->assertJsonPath('meta.total', 0);

    DB::table('reservation_records')->whereIn('id', $bookingIds)
        ->update(['lifecycle_state' => 'cancelled']);
    $this->getJson($endpoint.'?status=cancelled')->assertOk()
        ->assertJsonPath('data.0.status', 'cancelled');
    $this->getJson($endpoint.'?status=not_a_real_status')
        ->assertUnprocessable();
    $this->getJson($endpoint.'?search='.str_repeat('x', 121))
        ->assertUnprocessable();
});

it('reports pending evidence separately from reviewed and allocated balances', function (): void {
    [$hotel, $type, $guests, $staff] = groupFixture();
    $endpoint = '/api/v1/hotels/'.$hotel->id.'/group-reservations';
    $groupId = $this->postJson($endpoint, groupPayload($type, $guests))
        ->assertCreated()->json('data.id');
    $bookingIds = DB::table('group_reservation_members')
        ->where('hotel_id', $hotel->id)->where('group_reservation_id', $groupId)
        ->orderBy('position')->pluck('reservation_record_id')->all();

    $folios = [];
    foreach ($bookingIds as $bookingId) {
        $folioId = DB::table('reservation_folios')->insertGetId([
            'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
            'currency' => 'PHP', 'created_by' => $staff->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $folios[] = $folioId;
        DB::table('folio_entries')->insert([
            'hotel_id' => $hotel->id, 'folio_id' => $folioId,
            'entry_type' => 'room_night', 'description' => 'One room night',
            'amount_minor' => 250000, 'actor_id' => $staff->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    foreach ([
        [$folios[0], 'pending_review', 50000, 'PENDING-GROUP-1'],
        [$folios[1], 'staff_confirmed', 25000, 'CONFIRMED-GROUP-2'],
    ] as [$folioId, $status, $amount, $reference]) {
        DB::table('folio_payment_records')->insert([
            'hotel_id' => $hotel->id, 'folio_id' => $folioId,
            'request_key' => 'group-payment-'.$reference,
            'payload_sha256' => hash('sha256', $reference),
            'method' => 'bank_transfer', 'reference' => $reference,
            'amount_minor' => $amount, 'status' => $status,
            'recorded_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->getJson($endpoint.'/'.$groupId.'/financial-summary')->assertOk()
        ->assertJsonPath('data.currencies.0.netRecordedChargesMinor', 500000)
        ->assertJsonPath('data.currencies.0.pendingReviewPaymentsMinor', 50000)
        ->assertJsonPath('data.currencies.0.staffConfirmedPaymentsMinor', 25000)
        ->assertJsonPath('data.currencies.0.recordedPaymentsMinor', 75000)
        ->assertJsonPath('data.currencies.0.balanceAfterRecordedPaymentsMinor', 425000)
        ->assertJsonPath('data.currencies.0.unallocatedNetChargesMinor', 500000)
        ->assertJsonPath('data.rooms.0.pendingReviewPaymentCount', 1)
        ->assertJsonPath('data.rooms.0.staffConfirmedPaymentCount', 0)
        ->assertJsonPath('data.rooms.1.staffConfirmedPaymentCount', 1)
        ->assertJsonPath('data.rooms.1.independentlyVerifiedPaymentCount', 0)
        ->assertJsonPath('meta.finalInvoice', false);
});

it('shows linked housekeeping audit actors without exposing financial data in group history', function (): void {
    [$hotel, $type, $guests, $staff] = groupFixture();
    $endpoint = '/api/v1/hotels/'.$hotel->id.'/group-reservations';
    $id = $this->postJson($endpoint, groupPayload($type, $guests))
        ->assertCreated()->json('data.id');
    $booking = DB::table('group_reservation_members')->where('hotel_id', $hotel->id)
        ->where('group_reservation_id', $id)->orderBy('position')
        ->value('reservation_record_id');
    $room = Room::where('hotel_id', $hotel->id)->firstOrFail();
    $task = DB::table('housekeeping_tasks')->insertGetId([
        'hotel_id' => $hotel->id, 'room_id' => $room->id,
        'type' => 'checkout_clean', 'status' => 'todo',
        'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('reservation_turnover_links')->insert([
        'hotel_id' => $hotel->id, 'reservation_record_id' => $booking,
        'housekeeping_task_id' => $task, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('housekeeping_task_events')->insert([
        'hotel_id' => $hotel->id, 'task_id' => $task,
        'actor_id' => $staff->id, 'action' => 'created',
        'after_state' => json_encode(['status' => 'todo']),
        'created_at' => now(),
    ]);
    $response = $this->getJson($endpoint.'/'.$id)->assertOk()
        ->assertJsonPath('data.relatedOperationalEvents.0.source', 'housekeeping')
        ->assertJsonPath('data.relatedOperationalEvents.0.action', 'created')
        ->assertJsonPath('data.relatedOperationalEvents.0.actorName', 'Reception Staff')
        ->assertJsonPath('data.relatedOperationalEvents.0.bookingId', (string) $booking)
        ->assertJsonPath('data.relatedOperationalEventsLimited', false);
    expect($response->json('data.relatedOperationalEvents.0'))->not->toHaveKey('amountMinor');
});

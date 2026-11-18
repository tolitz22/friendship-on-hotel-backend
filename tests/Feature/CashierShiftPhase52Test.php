<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function cashier52User(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => 'Cashier Phase52 '.$role,
        'email' => uniqid('cashier52-', true).'@example.test',
        'password' => 'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    return $user;
}

function cashier52Hotel(): array
{
    $hotel = Hotel::create(['name' => 'Phase52 Hotel', 'currency' => 'PHP']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P52-101']);
    $guest = $hotel->guests()->create(['first_name' => 'Cash', 'last_name' => 'Guest']);
    $manager = cashier52User($hotel, 'admin');
    $cashier = cashier52User($hotel, 'front_desk');
    return compact('hotel','type','guest','manager','cashier');
}

it('opens a cashier shift, forbids duplicate open shifts, and disallows foreign cash drawers', function (): void {
    $fx = cashier52Hotel();
    $hotel = $fx['hotel'];
    $base = '/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($fx['cashier'], ['hotel:read','booking:write']);
    $id = $this->postJson($base.'/cashier-shifts', ['openingFloatMinor' => 10000])
        ->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
    $this->postJson($base.'/cashier-shifts', ['openingFloatMinor' => 10000])->assertStatus(409);
    $this->getJson($base.'/cashier-shifts')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($base.'/cashier-shifts/'.$id)->assertOk()
        ->assertJsonPath('data.expectedCashMinor', null);

    $other = cashier52User($hotel, 'front_desk');
    Sanctum::actingAs($other, ['hotel:read','booking:write']);
    $this->getJson($base.'/cashier-shifts/'.$id)->assertNotFound();
    $this->postJson($base.'/cashier-shifts/'.$id.'/close', ['countedCashMinor' => 10000])
        ->assertStatus(409);

    $cleaner = cashier52User($hotel, 'housekeeping');
    Sanctum::actingAs($cleaner, ['hotel:read']);
    $this->getJson($base.'/cashier-shifts')->assertForbidden();
    $this->postJson($base.'/cashier-shifts', ['openingFloatMinor' => 0])->assertForbidden();
    $this->getJson($base.'/cashier-shifts/'.$id.'/history')->assertForbidden();
});

it('blocks unlinked cash writes and enforces paid folio linkage with idempotent shift receipts', function (): void {
    $fx = cashier52Hotel();
    $hotel = $fx['hotel'];
    $base = '/api/v1/hotels/'.$hotel->id;
    $cashier = $fx['cashier'];
    Sanctum::actingAs($cashier, ['hotel:read','booking:write']);
    $shift = $this->postJson($base.'/cashier-shifts', ['openingFloatMinor' => 5000])
        ->assertCreated()->json('data.id');
    // An unpriced booking cannot receive an invented cash receipt.
    $this->postJson($base.'/cashier-shifts/'.$shift.'/bookings/999999/cash-receipts', [
        'requestKey' => 'phase52-missing-booking', 'reference' => 'CASH-52-MISSING', 'amountMinor' => 10000,
    ])->assertNotFound();

    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $this->postJson($base.'/bookings/999999/payment-records', [
        'requestKey' => 'phase52-legacy-123', 'method' => 'cash',
        'reference' => 'CASH-52-LEGACY', 'amountMinor' => 10000,
    ])->assertStatus(409);

    $otherHotel = Hotel::create(['name' => 'Phase52 Cross', 'currency' => 'PHP']);
    Sanctum::actingAs($cashier, ['hotel:read','booking:write']);
    $this->getJson('/api/v1/hotels/'.$otherHotel->id.'/cashier-shifts')->assertNotFound();

    expect(DB::table('cashier_shift_receipts')->where('hotel_id', $hotel->id)->count())->toBe(0);
});

it('requires independent manager review and flags shortages without mutating recorded cash', function (): void {
    $fx = cashier52Hotel();
    $hotel = $fx['hotel'];
    $base = '/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($fx['cashier'], ['hotel:read','booking:write']);
    $shift = $this->postJson($base.'/cashier-shifts', ['openingFloatMinor' => 20000])
        ->assertCreated()->json('data.id');
    $this->postJson($base.'/cashier-shifts/'.$shift.'/close', [
        'countedCashMinor' => 19500, 'note' => 'Counted at the counter',
    ])->assertOk()->assertJsonPath('data.varianceMinor', -500)
        ->assertJsonPath('data.status', 'submitted');
    $this->postJson($base.'/cashier-shifts/'.$shift.'/close', [
        'countedCashMinor' => 19500,
    ])->assertStatus(409);
    $this->postJson($base.'/cashier-shifts/'.$shift.'/reconcile', [
        'decision' => 'flag', 'note' => 'Short cash',
    ])->assertForbidden();

    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $this->postJson($base.'/cashier-shifts/'.$shift.'/reconcile', [
        'decision' => 'reconcile', 'note' => 'Manager validated cash drawer',
    ])->assertStatus(409);
    $this->postJson($base.'/cashier-shifts/'.$shift.'/reconcile', [
        'decision' => 'flag', 'note' => 'Cash shortage requires investigation.',
    ])->assertOk()->assertJsonPath('data.status', 'flagged');
    $this->postJson($base.'/cashier-shifts/'.$shift.'/reconcile', [
        'decision' => 'flag', 'note' => 'Duplicate review',
    ])->assertStatus(409);

    expect(DB::table('cashier_shift_events')->where('shift_id', $shift)
        ->orderBy('id')->pluck('event')->all())->toBe(['opened','submitted','flagged']);
});

it('requires a different manager to reconcile even when the cashier has admin role', function (): void {
    $fx = cashier52Hotel();
    $hotel = $fx['hotel'];
    $base = '/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($fx['manager'], ['hotel:read','hotel:write']);
    $shift = $this->postJson($base.'/cashier-shifts', ['openingFloatMinor' => 10000])
        ->assertCreated()->json('data.id');
    $this->postJson($base.'/cashier-shifts/'.$shift.'/close', [
        'countedCashMinor' => 10000,
    ])->assertOk();
    $this->postJson($base.'/cashier-shifts/'.$shift.'/reconcile', [
        'decision' => 'reconcile', 'note' => 'Self reconciliation blocked',
    ])->assertForbidden();
    $manager2 = cashier52User($hotel, 'admin');
    Sanctum::actingAs($manager2, ['hotel:read','hotel:write']);
    $this->postJson($base.'/cashier-shifts/'.$shift.'/reconcile', [
        'decision' => 'reconcile', 'note' => 'Independently counted float.',
    ])->assertOk()->assertJsonPath('data.status', 'reconciled')
        ->assertJsonPath('data.reviewedBy', (string) $manager2->id);
});

it('links an actual priced folio cash payment once and requires review before balanced reconciliation', function (): void {
    $fx = cashier52Hotel();
    $hotel = $fx['hotel'];
    $base = '/api/v1/hotels/'.$hotel->id;
    $manager = $fx['manager'];
    $cashier = $fx['cashier'];
    Sanctum::actingAs($manager, ['hotel:read','hotel:write']);
    $rateId = $this->postJson($base.'/rate-plans', [
        'roomTypeId' => $fx['type']->id,
        'name' => 'Phase 52 Nightly',
        'nightlyMinor' => 100000,
    ])->assertCreated()->json('data.id');
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'phase52-priced-'.uniqid(),
        'guestId' => $fx['guest']->id,
        'roomTypeId' => $fx['type']->id,
        'ratePlanId' => $rateId,
        'checkIn' => now()->addDays(10)->toDateString(),
        'checkOut' => now()->addDays(11)->toDateString(),
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($cashier, ['hotel:read','booking:write']);
    $shift = $this->postJson($base.'/cashier-shifts', ['openingFloatMinor' => 5000])
        ->assertCreated()->json('data.id');
    $endpoint = $base.'/cashier-shifts/'.$shift.'/bookings/'.$booking.'/cash-receipts';
    $body = [
        'requestKey' => 'phase52-actual-'.uniqid(),
        'reference' => 'CASH-P52-'.uniqid(),
        'amountMinor' => 10000,
    ];
    $payment = $this->postJson($endpoint, $body)->assertCreated()
        ->assertJsonPath('data.status', 'pending_review')->json('data.paymentId');
    $this->postJson($endpoint, $body)->assertOk()
        ->assertJsonPath('data.replayed', true);
    $this->postJson($endpoint, array_merge($body, ['amountMinor' => 20000]))
        ->assertStatus(409);
    expect(DB::table('cashier_shift_receipts')->where('shift_id', $shift)->count())->toBe(1);
    $this->getJson($base.'/cashier-shifts/'.$shift)->assertOk()
        ->assertJsonPath('data.receiptCount', 1)
        ->assertJsonPath('data.expectedCashMinor', null);

    $this->postJson($base.'/cashier-shifts/'.$shift.'/close', [
        'countedCashMinor' => 15000,
    ])->assertOk()->assertJsonPath('data.varianceMinor', 0);

    Sanctum::actingAs($manager, ['hotel:read','hotel:write']);
    $this->postJson($base.'/cashier-shifts/'.$shift.'/reconcile', [
        'decision' => 'reconcile', 'note' => 'Cash physically counted.',
    ])->assertStatus(409);
    $this->postJson($base.'/bookings/'.$booking.'/payment-records/'.$payment.'/review', [
        'decision' => 'confirm', 'note' => 'Verified counted cash evidence.',
    ])->assertOk();
    $this->postJson($base.'/cashier-shifts/'.$shift.'/reconcile', [
        'decision' => 'reconcile', 'note' => 'Balanced drawer verified independently.',
    ])->assertOk()->assertJsonPath('data.status', 'reconciled')
        ->assertJsonPath('data.expectedCashMinor', 15000);

    expect(DB::table('folio_payment_records')->where('id', $payment)->value('method'))
        ->toBe('cash');
    expect(DB::table('cashier_shift_events')->where('shift_id', $shift)
        ->orderBy('id')->pluck('event')->all())
        ->toBe(['opened','cash_received','submitted','reconciled']);
});

it('shows only the signed-in cashier\'s open shift even when a manager can list every employee', function (): void {
    $fx = cashier52Hotel();
    $base = '/api/v1/hotels/'.$fx['hotel']->id;
    Sanctum::actingAs($fx['cashier'], ['hotel:read', 'booking:write']);
    $cashierShift = $this->postJson($base.'/cashier-shifts', [
        'openingFloatMinor' => 7500,
    ])->assertCreated()->json('data.id');
    $this->getJson($base.'/cashier-shifts/current')->assertOk()
        ->assertJsonPath('data.id', $cashierShift)
        ->assertJsonPath('data.cashierId', (string) $fx['cashier']->id);

    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->getJson($base.'/cashier-shifts')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($base.'/cashier-shifts/current')->assertOk()
        ->assertJsonPath('data', null);
    $managerShift = $this->postJson($base.'/cashier-shifts', [
        'openingFloatMinor' => 3000,
    ])->assertCreated()->json('data.id');
    $this->getJson($base.'/cashier-shifts/current')->assertOk()
        ->assertJsonPath('data.id', $managerShift)
        ->assertJsonPath('data.cashierId', (string) $fx['manager']->id);
    $this->getJson($base.'/cashier-shifts')->assertOk()->assertJsonCount(2, 'data');

    Sanctum::actingAs($fx['cashier'], ['hotel:read', 'booking:write']);
    $this->getJson($base.'/cashier-shifts/current')->assertOk()
        ->assertJsonPath('data.id', $cashierShift);
    $this->getJson($base.'/cashier-shifts/'.$managerShift)->assertNotFound();

    $housekeeper = cashier52User($fx['hotel'], 'housekeeping');
    Sanctum::actingAs($housekeeper, ['hotel:read']);
    $this->getJson($base.'/cashier-shifts/current')->assertForbidden();
});

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

<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase26Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Phase26 Property', 'currency' => 'PHP']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'P26-1']);
    $guest = $hotel->guests()->create(['first_name' => 'Guest', 'last_name' => 'Phase26']);
    $staff = User::create(['name' => 'Finance Author', 'email' => uniqid('p26a-').'@example.test', 'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($staff, ['hotel:read', 'hotel:write']);
    $rate = $testRate = DB::table('room_rate_plans')->insertGetId([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'name' => 'BAR',
        'nightly_minor' => 10001, 'currency' => 'PHP', 'is_active' => true,
        'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $booking = test()->postJson('/api/v1/hotels/'.$hotel->id.'/bookings', [
        'idempotencyKey' => 'phase26-book-'.uniqid(),
        'guestId' => $guest->id, 'roomTypeId' => $type->id, 'ratePlanId' => $rate,
        'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12',
    ])->assertCreated()->json('data.id');
    return [$hotel, $booking, $staff];
}

function phase26Reviewer(Hotel $hotel): void
{
    $reviewer = User::create(['name' => 'Independent Finance Reviewer',
        'email' => uniqid('p26r-').'@example.test', 'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $reviewer->id,
        'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($reviewer, ['hotel:read', 'hotel:write']);
}

function phase26Payment(Hotel $hotel, string $booking): string
{
    $base = '/api/v1/hotels/'.$hotel->id.'/bookings/'.$booking;
    $id = test()->postJson($base.'/payment-records', [
        'requestKey' => 'phase26-payment-'.uniqid(), 'method' => 'cash',
        'reference' => 'CASH-'.uniqid(), 'amountMinor' => 18000,
    ])->assertCreated()->json('data.id');
    test()->postJson($base.'/payment-records/'.$id.'/review', [
        'decision' => 'confirm', 'note' => 'Counter cash evidence reviewed',
    ])->assertOk();
    return $id;
}

it('versions inactive tax and service previews with exact integer rounding', function (): void {
    [$hotel, $booking] = phase26Fixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    $draft = [
        'taxBasisPoints' => 1200, 'serviceBasisPoints' => 1000,
        'taxLabel' => 'Hypothetical tax', 'serviceLabel' => 'Hypothetical service',
        'reason' => 'Internal illustration awaiting financial signoff',
    ];
    $id = $this->postJson($base.'/finance/charge-policy-drafts', $draft)
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    $this->postJson($base.'/finance/charge-policy-drafts', $draft)
        ->assertCreated()->assertJsonPath('data.version', 2);
    $this->getJson($base.'/bookings/'.$booking.'/charge-preview?policyId='.$id)
        ->assertOk()->assertJsonPath('data.roomSubtotalMinor', 20002)
        ->assertJsonPath('data.hypotheticalTaxMinor', 2400)
        ->assertJsonPath('data.hypotheticalServiceMinor', 2000)
        ->assertJsonPath('data.appliedToFolio', false)
        ->assertJsonPath('data.isFinalPayableTotal', false);
    $this->getJson($base.'/bookings/'.$booking.'/folio-ledger')
        ->assertOk()->assertJsonPath('data.summary.taxesCalculated', false);
    expect(DB::table('folio_entries')->count())->toBe(2);
});

it('records refund requests against reviewed payments but never marks funds disbursed', function (): void {
    [$hotel, $booking] = phase26Fixture();
    $paymentId = phase26Payment($hotel, $booking);
    $base = '/api/v1/hotels/'.$hotel->id.'/bookings/'.$booking;
    $input = [
        'requestKey' => 'phase26-refund-001', 'paymentRecordId' => $paymentId,
        'amountMinor' => 12000, 'reason' => 'Guest requested partial refund',
    ];
    $id = $this->postJson($base.'/refund-requests', $input)
        ->assertCreated()->assertJsonPath('data.fundsDisbursed', false)->json('data.id');
    $this->postJson($base.'/refund-requests', $input)->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.replayed', true);
    $this->postJson($base.'/refund-requests', array_replace($input, [
        'requestKey' => 'phase26-refund-002', 'amountMinor' => 7000,
    ]))->assertStatus(409);
    $this->postJson($base.'/refund-requests/'.$id.'/review', [
        'decision' => 'approve', 'note' => 'Refund reviewed',
    ])->assertForbidden();

    phase26Reviewer($hotel);
    $this->postJson($base.'/refund-requests/'.$id.'/review', [
        'decision' => 'approve', 'note' => 'Approved internal review only',
    ])->assertOk()->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.fundsDisbursed', false);
    $this->postJson($base.'/refund-requests/'.$id.'/review', [
        'decision' => 'reject', 'note' => 'Duplicate review',
    ])->assertStatus(409);
    $this->getJson($base.'/refund-requests')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'approved');
    expect(DB::table('folio_payment_records')->find($paymentId)->status)->toBe('staff_confirmed');
});

it('rejects unconfirmed payments for refund requests and isolates properties', function (): void {
    [$hotel, $booking] = phase26Fixture();
    $base = '/api/v1/hotels/'.$hotel->id.'/bookings/'.$booking;
    $id = $this->postJson($base.'/payment-records', [
        'requestKey' => 'phase26-pending-001', 'method' => 'cash',
        'reference' => 'CASH-PENDING-26', 'amountMinor' => 4000,
    ])->assertCreated()->json('data.id');
    $this->postJson($base.'/refund-requests', [
        'requestKey' => 'phase26-refund-pend', 'paymentRecordId' => $id,
        'amountMinor' => 1000, 'reason' => 'Attempt on pending evidence',
    ])->assertStatus(409);

    $other = Hotel::create(['name' => 'Different Hotel', 'currency' => 'PHP']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/finance/charge-policy-drafts')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$other->id.'/bookings/'.$booking.'/refund-requests')->assertNotFound();
    $this->getJson($base.'/bookings/999')->assertNotFound();
});

it('restricts policy and refund mutations to property admins', function (): void {
    [$hotel, $booking] = phase26Fixture();
    $person = User::create(['name' => 'Read Member', 'email' => uniqid('p26m-').'@example.test',
        'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $person->id,
        'role' => 'front_desk', 'is_active' => true]);
    Sanctum::actingAs($person, ['hotel:read', 'hotel:write']);
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->getJson($base.'/finance/charge-policy-drafts')->assertOk();
    $this->postJson($base.'/finance/charge-policy-drafts', [
        'taxBasisPoints' => 1200, 'serviceBasisPoints' => 1000,
        'taxLabel' => 'Tax', 'serviceLabel' => 'Service',
        'reason' => 'Attempted unauthorized policy draft',
    ])->assertForbidden();
    $this->postJson($base.'/bookings/'.$booking.'/refund-requests', [
        'requestKey' => 'phase26-no-access', 'paymentRecordId' => 1,
        'amountMinor' => 1000, 'reason' => 'Unauthorized refund',
    ])->assertForbidden();
});

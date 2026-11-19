<?php

declare(strict_types=1);

use App\Domain\Reservations\CashierShiftService;
use App\Domain\Reservations\FolioAccountingService;
use App\Domain\Reservations\ReservationStayLifecycleService;
use App\Domain\Reservations\SimplifiedCashCheckoutPolicy;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function simplifiedCashFixture(): array
{
    $hotel = Hotel::create(['name' => 'Cashier Policy Test Hotel', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create([
        'hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2,
    ]);
    $room = Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '105',
    ]);
    $cashier = User::create([
        'name' => 'Cashier A', 'email' => 'simple-cashier@example.test',
        'password' => 'testing-only', 'is_active' => true,
    ]);
    $owner = User::create([
        'name' => 'Owner B', 'email' => 'simple-owner@example.test',
        'password' => 'testing-only', 'is_active' => true,
    ]);
    foreach ([[$cashier, 'front_desk'], [$owner, 'admin']] as [$user, $role]) {
        HotelMembership::create([
            'hotel_id' => $hotel->id, 'user_id' => $user->id,
            'role' => $role, 'is_active' => true,
        ]);
    }
    $booking = DB::table('reservation_records')->insertGetId([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'assigned_room_id' => $room->id,
        'reference' => 'SIMPLE-CASH-BOOKING-1',
        'check_in' => now('Asia/Manila')->subDay()->toDateString(),
        'check_out' => now('Asia/Manila')->toDateString(),
        'lifecycle_state' => 'checked_in', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $folio = DB::table('reservation_folios')->insertGetId([
        'hotel_id' => $hotel->id, 'reservation_record_id' => $booking,
        'currency' => 'PHP', 'created_by' => $cashier->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('folio_entries')->insert([
        'hotel_id' => $hotel->id, 'folio_id' => $folio,
        'entry_type' => 'room_night', 'description' => 'Nightly room charge',
        'stay_date' => now('Asia/Manila')->subDay()->toDateString(),
        'amount_minor' => 840000, 'actor_id' => $cashier->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return [$hotel, $booking, $folio, $cashier, $owner];
}

it('accepts ordinary cash without manager payment approval and permits cash-only checkout before drawer reconciliation', function (): void {
    config()->set('financial_accounting.simplified_cashier_mode', true);
    config()->set('financial_accounting.enable_verified_settlement', true);
    config()->set('financial_accounting.enable_live_folio_tax', false);
    [$hotel, $booking, $folio, $cashier, $owner] = simplifiedCashFixture();
    $shifts = app(CashierShiftService::class);
    $open = $shifts->open($hotel, $cashier->id, 200000);
    $receipt = $shifts->receive($hotel, $cashier->id, (int) $open['id'], $booking, [
        'requestKey' => 'simple-cash-tender-10000',
        'reference' => 'SIMPLE-RECEIPT-ONE',
        'amountMinor' => 840000, 'cashTenderedMinor' => 1000000,
        'changeGivenMinor' => 160000,
    ]);
    expect($receipt['status'])->toBe('staff_confirmed')
        ->and($shifts->details($hotel, $cashier->id, false, (int) $open['id'])['status'])->toBe('open')
        ->and(DB::table('folio_settlement_signoffs')->count())->toBe(0);
    $this->assertDatabaseHas('folio_payment_records', [
        'id' => $receipt['paymentId'], 'status' => 'staff_confirmed',
        'reviewed_by' => null,
    ]);
    app(SimplifiedCashCheckoutPolicy::class)->requireEligible($hotel, $booking);
    $checkout = app(ReservationStayLifecycleService::class)->transition(
        $hotel, $cashier->id, $booking, 'check_out'
    );
    expect($checkout['status'])->toBe('checked_out')
        ->and(DB::table('folio_settlement_signoffs')->count())->toBe(0);
    expect(DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
        ->where('type', 'checkout_clean')->count())->toBe(1);
    $audit = DB::table('simplified_cash_checkout_audits')->where('hotel_id', $hotel->id)
        ->where('reservation_record_id', $booking)->first();
    expect($audit)->not->toBeNull()
        ->and((int) $audit->recorded_charges_minor)->toBe(840000)
        ->and((int) $audit->net_cash_minor)->toBe(840000)
        ->and((int) $audit->receipt_count)->toBe(1)
        ->and((int) $audit->performed_by)->toBe($cashier->id)
        ->and(hash('sha256', $audit->receipt_snapshot))->toBe($audit->receipt_snapshot_sha256);
    $auditView = app(\App\Domain\Reservations\FolioSettlementService::class)
        ->statement($hotel, $booking)['routineCashCheckoutAudit'];
    expect($auditView['integrityValid'])->toBeTrue()
        ->and($auditView['performedByName'])->toBe('Cashier A')
        ->and($auditView['isIndependentFinancialSettlement'])->toBeFalse();

    // The shift remains independently reviewable after the guest departs.
    $shifts->close($hotel, $cashier->id, (int) $open['id'], 1040000,
        'Physical cash including opening float.');
    $reviewed = $shifts->reconcile($hotel, $owner->id, (int) $open['id'],
        'reconcile', 'Checked receipts against physically counted drawer cash.');
    expect($reviewed['status'])->toBe('reconciled');
});

it('refuses simplified checkout when guest payment is missing or a bank transfer is unverified', function (): void {
    config()->set('financial_accounting.simplified_cashier_mode', true);
    [$hotel, $booking, $folio, $cashier] = simplifiedCashFixture();
    $policy = app(SimplifiedCashCheckoutPolicy::class);
    try {
        $policy->requireEligible($hotel, $booking);
        test()->fail('No receipt cannot be treated as settled cash.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    app(FolioAccountingService::class)->recordPayment($hotel, $booking, $cashier->id, [
        'requestKey' => 'unverified-bank-receipt',
        'method' => 'bank_transfer', 'reference' => 'BANK-NOT-VERIFIED',
        'amountMinor' => 840000,
    ]);
    try {
        $policy->requireEligible($hotel, $booking);
        test()->fail('Bank transfer cannot take cash-only fast checkout.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
});

it('blocks cash-only checkout when cash tender breakdown is missing or a correction exists', function (): void {
    config()->set('financial_accounting.simplified_cashier_mode', true);
    [$hotel, $booking, $folio, $cashier, $owner] = simplifiedCashFixture();
    $shifts = app(CashierShiftService::class);
    $open = $shifts->open($hotel, $cashier->id, 0);
    $receipt = $shifts->receive($hotel, $cashier->id, (int) $open['id'], $booking, [
        'requestKey' => 'legacy-cash-no-breakdown',
        'reference' => 'LEGACY-CASH-SIMPLE', 'amountMinor' => 840000,
    ]);
    expect($receipt['status'])->toBe('pending_review');
    try {
        app(SimplifiedCashCheckoutPolicy::class)->requireEligible($hotel, $booking);
        test()->fail('Legacy cash evidence cannot bypass manager review.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    app(FolioAccountingService::class)->review($hotel, $booking,
        (int) $receipt['paymentId'], $owner->id,
        ['decision' => 'confirm', 'note' => 'Cash receipt reviewed.']);
    try {
        app(SimplifiedCashCheckoutPolicy::class)->requireEligible($hotel, $booking);
        test()->fail('Missing tender/change remains ineligible despite confirmation.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
});

it('does not allow ordinary folio changes after simplified checkout', function (): void {
    config()->set('financial_accounting.simplified_cashier_mode', true);
    [$hotel, $booking, $folio, $cashier, $owner] = simplifiedCashFixture();
    DB::table('reservation_records')->where('id', $booking)->update([
        'lifecycle_state' => 'checked_out',
    ]);
    try {
        app(FolioAccountingService::class)->recordPayment($hotel, $booking, $cashier->id, [
            'requestKey' => 'late-cash-bank-mutation',
            'method' => 'bank_transfer', 'reference' => 'AFTER-CHECKOUT-PAYMENT',
            'amountMinor' => 1000,
        ]);
        test()->fail('Ordinary post-checkout payment writes should remain locked.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
});

it('keeps the original independent-approval checkout policy when simplified mode is disabled', function (): void {
    config()->set('financial_accounting.simplified_cashier_mode', false);
    config()->set('financial_accounting.enable_verified_settlement', true);
    [$hotel, $booking, $folio, $cashier] = simplifiedCashFixture();
    $shifts = app(CashierShiftService::class);
    $open = $shifts->open($hotel, $cashier->id, 0);
    $receipt = $shifts->receive($hotel, $cashier->id, (int) $open['id'], $booking, [
        'requestKey' => 'strict-mode-receipt-01', 'reference' => 'STRICT-CASH-PAYMENT',
        'amountMinor' => 840000, 'cashTenderedMinor' => 1000000,
        'changeGivenMinor' => 160000,
    ]);
    expect($receipt['status'])->toBe('pending_review');
    try {
        app(ReservationStayLifecycleService::class)->transition(
            $hotel, $cashier->id, $booking, 'check_out'
        );
        test()->fail('Strict mode must still require signed financial settlement.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
});

it('permits an authorized front desk checkout through the API without an independent manager payment signoff', function (): void {
    config()->set('financial_accounting.simplified_cashier_mode', true);
    config()->set('financial_accounting.enable_verified_settlement', true);
    [$hotel, $booking, $folio, $cashier, $owner] = simplifiedCashFixture();
    $shifts = app(CashierShiftService::class);
    $open = $shifts->open($hotel, $cashier->id, 0);
    $shifts->receive($hotel, $cashier->id, (int) $open['id'], $booking, [
        'requestKey' => 'front-desk-api-fast-checkout-01',
        'reference' => 'CASH-CHECKOUT-API', 'amountMinor' => 840000,
        'cashTenderedMinor' => 1000000, 'changeGivenMinor' => 160000,
    ]);
    Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/bookings/'.$booking.'/check-out')
        ->assertOk()->assertJsonPath('data.status', 'checked_out');
    expect(DB::table('cashier_shifts')->where('id', $open['id'])->value('status'))
        ->toBe('open');
    $this->assertDatabaseHas('reservation_records', [
        'id' => $booking, 'lifecycle_state' => 'checked_out',
    ]);
    expect(DB::table('folio_settlement_signoffs')->where('hotel_id', $hotel->id)->count())
        ->toBe(0);
});

it('detects out-of-band modification of an operational cash checkout snapshot', function (): void {
    config()->set('financial_accounting.simplified_cashier_mode', true);
    [$hotel, $booking, $folio, $cashier] = simplifiedCashFixture();
    $shift = app(CashierShiftService::class)->open($hotel, $cashier->id, 0);
    app(CashierShiftService::class)->receive($hotel, $cashier->id, (int) $shift['id'], $booking, [
        'requestKey' => 'audit-immutable-tampering',
        'reference' => 'SIMPLE-AUDIT-INTEGRITY', 'amountMinor' => 840000,
        'cashTenderedMinor' => 1000000, 'changeGivenMinor' => 160000,
    ]);
    app(ReservationStayLifecycleService::class)->transition($hotel, $cashier->id, $booking, 'check_out');
    DB::table('simplified_cash_checkout_audits')->where('hotel_id', $hotel->id)
        ->where('reservation_record_id', $booking)
        ->update(['receipt_snapshot' => '{"changed":true}']);
    $view = app(\App\Domain\Reservations\FolioSettlementService::class)
        ->statement($hotel, $booking)['routineCashCheckoutAudit'];
    expect($view['integrityValid'])->toBeFalse();
});

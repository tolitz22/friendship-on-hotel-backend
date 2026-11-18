<?php

declare(strict_types=1);

use App\Domain\Reservations\CashierShiftService;
use App\Domain\Reservations\FolioAccountingService;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function paymentControlFixture(): array
{
    $hotel = Hotel::create(['name' => 'Payment Safeguards', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $recorder = User::create(['name' => 'Cashier', 'email' => 'void-cashier@example.test',
        'password' => 'not-a-secret', 'is_active' => true]);
    $manager = User::create(['name' => 'Independent Manager', 'email' => 'void-manager@example.test',
        'password' => 'not-a-secret', 'is_active' => true]);
    foreach ([[$recorder, 'front_desk'], [$manager, 'admin']] as [$user, $role]) {
        HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id,
            'role' => $role, 'is_active' => true]);
    }
    $bookingId = DB::table('reservation_records')->insertGetId([
        'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'reference' => 'BK-VOID-TEST',
        'check_in' => now('Asia/Manila')->addDay()->toDateString(),
        'check_out' => now('Asia/Manila')->addDays(2)->toDateString(),
        'lifecycle_state' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $folioId = DB::table('reservation_folios')->insertGetId([
        'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
        'currency' => 'PHP', 'created_by' => $recorder->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return [$hotel, $bookingId, $folioId, $recorder, $manager];
}

function bankPaymentInput(string $key, string $ref, array $extra = []): array
{
    return [
        'requestKey' => $key, 'method' => 'bank_transfer',
        'reference' => $ref, 'amountMinor' => 840000, ...$extra,
    ];
}

it('blocks distinct-key duplicate bank records even though both references are different', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $accounting = app(FolioAccountingService::class);
    $one = $accounting->recordPayment($hotel, $booking, $cashier->id,
        bankPaymentInput('first-bank-record-0001', 'TRANSFER-ABC-001'));
    expect($one['replayed'])->toBeFalse();
    try {
        $accounting->recordPayment($hotel, $booking, $manager->id,
            bankPaymentInput('second-bank-record-002', 'TRANSFER-ABC-002'));
        test()->fail('Second bank evidence must not be accepted without an exception.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    expect(DB::table('folio_payment_records')->where('hotel_id', $hotel->id)->count())->toBe(1);
    $replayed = $accounting->recordPayment($hotel, $booking, $cashier->id,
        bankPaymentInput('first-bank-record-0001', 'TRANSFER-ABC-001'));
    expect($replayed['replayed'])->toBeTrue();
});

it('records a reason and approver for genuine second payments while rejecting unauthorized overrides', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $accounting = app(FolioAccountingService::class);
    $accounting->recordPayment($hotel, $booking, $cashier->id,
        bankPaymentInput('first-bank-evidence-1', 'TRANSFER-GENUINE-ONE'));
    try {
        $accounting->recordPayment($hotel, $booking, $cashier->id,
            bankPaymentInput('second-bank-evidence-2', 'TRANSFER-GENUINE-TWO', [
                'duplicateOverrideReason' => 'Separate physical transfer from same guest.',
            ]));
        test()->fail('Unauthorized duplicate exception should be rejected.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    $second = $accounting->recordPayment($hotel, $booking, $manager->id,
        bankPaymentInput('second-bank-evidence-2', 'TRANSFER-GENUINE-TWO', [
            'duplicateOverrideAuthorized' => true,
            'duplicateOverrideReason' => 'Guest presented independently distinct transfer confirmation.',
        ]));
    $this->assertDatabaseHas('folio_payment_duplicate_overrides', [
        'hotel_id' => $hotel->id, 'payment_record_id' => $second['id'],
        'approved_by' => $manager->id,
    ]);
    expect(DB::table('folio_payment_records')->where('hotel_id', $hotel->id)->count())->toBe(2);
});

it('manager can void an unverified error, leaving immutable audit history and releasing the duplicate warning', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $accounting = app(FolioAccountingService::class);
    $payment = $accounting->recordPayment($hotel, $booking, $cashier->id,
        bankPaymentInput('mistake-bank-payment-1', 'TRANSFER-MISTAKE-ONE'));
    $id = (int) $payment['id'];
    try {
        $accounting->voidPayment($hotel, $booking, $id, $cashier->id,
            'attempt-by-recorder-1', 'Duplicate payment entered by mistake.');
        test()->fail('Recorder must not void own evidence.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
    $void = $accounting->voidPayment($hotel, $booking, $id, $manager->id,
        'void-mistaken-bank-01', 'Second entry created although no second transfer was received.');
    expect($void['status'])->toBe('voided');
    expect($accounting->voidPayment($hotel, $booking, $id, $manager->id,
        'void-mistaken-bank-01', 'Second entry created although no second transfer was received.')['replayed'])->toBeTrue();
    $this->assertDatabaseHas('folio_payment_records', ['id' => $id, 'status' => 'voided']);
    $this->assertDatabaseHas('folio_payment_voids', [
        'payment_record_id' => $id, 'voided_by' => $manager->id,
        'original_status' => 'pending_review',
    ]);
    expect($accounting->summary($hotel, $booking)['pendingReviewPaymentsMinor'])->toBe(0);
    expect(app(\App\Domain\Reservations\FolioSettlementService::class)
        ->statement($hotel, $booking)['paymentEvidenceIndependentlyReviewed'])->toBeTrue();
    expect($accounting->paymentSafety($hotel, $booking, 'bank_transfer', 840000)['recentMatchingPaymentIds'])->toBe([]);
});

it('cash void before shift closure removes the erroneous receipt from cash totals without deleting history', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $shifts = app(CashierShiftService::class);
    $opened = $shifts->open($hotel, $cashier->id, 10000);
    $cash = $shifts->receive($hotel, $cashier->id, (int) $opened['id'], $booking, [
        'requestKey' => 'cash-receipt-to-void-01', 'reference' => 'CASH-VOID-ONE',
        'amountMinor' => 840000,
    ]);
    $result = app(FolioAccountingService::class)->voidPayment($hotel, $booking,
        (int) $cash['paymentId'], $manager->id, 'void-open-cash-payment-1',
        'The same cash was accidentally recorded on the account twice.');
    expect($result['status'])->toBe('voided');
    $details = $shifts->details($hotel, $manager->id, true, (int) $opened['id']);
    expect($details['receiptCount'])->toBe(0);
    expect($details['receipts'][0]['paymentStatus'])->toBe('voided');
    $closed = $shifts->close($hotel, $cashier->id, (int) $opened['id'], 10000, 'Drawer counted.');
    expect($closed['expectedCashMinor'])->toBe(10000);
    expect(DB::table('cashier_shift_events')->where('hotel_id', $hotel->id)
        ->where('event', 'payment_voided')->count())->toBe(1);
});

it('forbids voiding receipts from closed cashier shifts or payments with settlement allocations', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $shifts = app(CashierShiftService::class);
    $opened = $shifts->open($hotel, $cashier->id, 0);
    $cash = $shifts->receive($hotel, $cashier->id, (int) $opened['id'], $booking, [
        'requestKey' => 'cash-closed-to-void-1', 'reference' => 'CASH-CLOSED-VOID',
        'amountMinor' => 840000,
    ]);
    $shifts->close($hotel, $cashier->id, (int) $opened['id'], 840000, 'Verified count.');
    try {
        app(FolioAccountingService::class)->voidPayment($hotel, $booking,
            (int) $cash['paymentId'], $manager->id, 'void-closed-cash-001',
            'Cashier shift has already been closed and must remain auditable.');
        test()->fail('Closed cash shift must be immutable.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    expect(DB::table('folio_payment_voids')->where('hotel_id', $hotel->id)->count())->toBe(0);
});

it('exposes payment history but restricts voiding to a different hotel administrator', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $payment = app(FolioAccountingService::class)->recordPayment($hotel, $booking,
        $cashier->id, bankPaymentInput('readable-history-payment-1', 'TRANSFER-VISIBLE'));
    $base = '/api/v1/hotels/'.$hotel->id.'/bookings/'.$booking;
    Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $this->getJson($base.'/payment-safety')->assertOk()
        ->assertJsonPath('data.payments.0.recordedByName', 'Cashier');
    $this->postJson($base.'/payment-records/'.$payment['id'].'/void', [
        'requestKey' => 'front-desk-void-attempt-01',
        'reason' => 'Cashier must not approve their own void.',
    ])->assertForbidden();
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->postJson($base.'/payment-records/'.$payment['id'].'/void', [
        'requestKey' => 'manager-void-request-0001',
        'reason' => 'Incorrect transfer reference, no actual bank receipt.',
    ])->assertCreated()->assertJsonPath('data.status', 'voided');
});

it('blocks voiding once independent financial verification has started', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $accounting = app(FolioAccountingService::class);
    $payment = $accounting->recordPayment($hotel, $booking, $cashier->id,
        bankPaymentInput('bank-reviewed-before-void', 'BANK-REVIEWED-BEFORE-VOID'));
    $accounting->review($hotel, $booking, (int) $payment['id'], $manager->id, [
        'decision' => 'confirm', 'note' => 'Supporting statement was inspected.',
    ]);
    config()->set('financial_accounting.enable_verified_settlement', true);
    app(\App\Domain\Reservations\FolioSettlementService::class)->prepareVerification(
        $hotel, $booking, (int) $payment['id'], $manager->id,
        'bank_statement', 'BANK-VERIFICATION-BLOCK-VOID'
    );
    try {
        $accounting->voidPayment($hotel, $booking, (int) $payment['id'],
            $manager->id, 'void-after-verification-1',
            'This previously reviewed deposit must use finance correction instead.');
        test()->fail('Started verification must block ordinary voiding.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    $this->assertDatabaseHas('folio_payment_records', [
        'id' => $payment['id'], 'status' => 'staff_confirmed',
    ]);
    expect(DB::table('folio_payment_voids')->count())->toBe(0);
});

it('allows independent void of rejected mistaken cash evidence before drawer closure', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $shifts = app(CashierShiftService::class);
    $open = $shifts->open($hotel, $cashier->id, 0);
    $received = $shifts->receive($hotel, $cashier->id, (int) $open['id'], $booking, [
        'requestKey' => 'cash-pending-then-rejected', 'reference' => 'CASH-REJECTED-ERR',
        'amountMinor' => 840000,
    ]);
    app(FolioAccountingService::class)->review($hotel, $booking,
        (int) $received['paymentId'], $manager->id, [
            'decision' => 'reject', 'note' => 'No second cash was received.',
        ]);
    $result = app(FolioAccountingService::class)->voidPayment($hotel, $booking,
        (int) $received['paymentId'], $manager->id,
        'void-rejected-cash-evidence',
        'Duplicate mistaken record without any additional physical money received.');
    expect($result['status'])->toBe('voided');
    $closed = $shifts->close($hotel, $cashier->id, (int) $open['id'], 0, 'No extra cash entered.');
    expect($closed['expectedCashMinor'])->toBe(0);
});

it('stops accidental second cash receipts across staff and records a manager-authorized exception', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $base = '/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $cashierShift = $this->postJson($base.'/cashier-shifts', [
        'openingFloatMinor' => 0,
    ])->assertCreated()->json('data.id');
    $receipt = $base.'/cashier-shifts/'.$cashierShift.'/bookings/'.$booking.'/cash-receipts';
    $this->postJson($receipt, [
        'requestKey' => 'first-real-cash-receipt-1',
        'reference' => 'CASH-EXACT-ONE',
        'amountMinor' => 840000,
    ])->assertCreated();
    $this->postJson($receipt, [
        'requestKey' => 'second-accidental-cash-2',
        'reference' => 'CASH-EXACT-TWO', 'amountMinor' => 840000,
        'duplicateOverrideReason' => 'Guest made a second cash payment, independently checked.',
    ])->assertStatus(409);
    expect(DB::table('cashier_shift_receipts')->where('hotel_id', $hotel->id)->count())->toBe(1);

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $managerShift = $this->postJson($base.'/cashier-shifts', [
        'openingFloatMinor' => 0,
    ])->assertCreated()->json('data.id');
    $this->postJson($base.'/cashier-shifts/'.$managerShift.'/bookings/'.$booking.'/cash-receipts', [
        'requestKey' => 'second-genuine-cash-receipt-2',
        'reference' => 'CASH-EXACT-THREE', 'amountMinor' => 840000,
        'duplicateOverrideReason' => 'Second distinct cash tender was counted in the manager drawer.',
    ])->assertCreated();
    expect(DB::table('cashier_shift_receipts')->where('hotel_id', $hotel->id)->count())->toBe(2);
    $this->assertDatabaseHas('folio_payment_duplicate_overrides', [
        'hotel_id' => $hotel->id, 'approved_by' => $manager->id,
    ]);
});

it('reports the correct cash shift for a guest payment through open, submitted and reconciled states', function (): void {
    [$hotel, $booking, $folio, $cashier, $manager] = paymentControlFixture();
    $shifts = app(CashierShiftService::class);
    $accounting = app(FolioAccountingService::class);
    $settlement = app(\App\Domain\Reservations\FolioSettlementService::class);
    $opened = $shifts->open($hotel, $cashier->id, 0);
    $cash = $shifts->receive($hotel, $cashier->id, (int) $opened['id'], $booking, [
        'requestKey' => 'payment-linked-shift-state-1',
        'reference' => 'CASH-SHIFT-STATE',
        'amountMinor' => 840000,
    ]);
    $paymentId = (string) $cash['paymentId'];
    $handoff = collect($settlement->statement($hotel, $booking)['cashShiftContexts'])
        ->firstWhere('paymentId', $paymentId);
    expect($handoff['cashShiftId'])->toBe((string) $opened['id'])
        ->and($handoff['cashShiftStatus'])->toBe('open')
        ->and($handoff['cashierName'])->toBe('Cashier');

    $accounting->review($hotel, $booking, (int) $paymentId, $manager->id, [
        'decision' => 'confirm', 'note' => 'Reviewed actual receipt evidence.',
    ]);
    $shifts->close($hotel, $cashier->id, (int) $opened['id'],
        840000, 'Counted physical cash after guest payment.');
    $submitted = collect($settlement->statement($hotel, $booking)['cashShiftContexts'])
        ->firstWhere('paymentId', $paymentId);
    expect($submitted['cashShiftStatus'])->toBe('submitted');

    $shifts->reconcile($hotel, $manager->id, (int) $opened['id'],
        'reconcile', 'Reviewed the physical cash and receipt records.');
    $reviewed = collect($settlement->statement($hotel, $booking)['cashShiftContexts'])
        ->firstWhere('paymentId', $paymentId);
    expect($reviewed['cashShiftStatus'])->toBe('reconciled')
        ->and($reviewed['reconciledByName'])->toBe('Independent Manager')
        ->and($reviewed['recordedByName'])->toBe('Cashier');

    // Shift state is presentation metadata only, not an altered guest bill.
    expect($settlement->statement($hotel, $booking)['verifiedAllocatedMinor'])->toBe(0);
});

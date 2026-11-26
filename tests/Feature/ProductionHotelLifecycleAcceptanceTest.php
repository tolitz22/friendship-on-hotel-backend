<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('completes a priced stay with independent bank evidence, earned revenue, settlement and room turnover', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-17T12:00:00+08:00'));
    config()->set([
        'financial_accounting.enable_verified_settlement' => true,
        'financial_accounting.enable_live_folio_tax' => true,
        'financial_accounting.simplified_cashier_mode' => false,
    ]);
    $hotel = Hotel::create(['name' => 'Synthetic lifecycle hotel', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    $staff = function (string $name) use ($hotel): User {
        $user = User::create(['name' => $name, 'email' => uniqid('lifecycle-', true).'@example.test',
            'password' => 'SyntheticPassword123!']);
        HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
        return $user;
    };
    $recorder = $staff('Lifecycle recorder');
    $reviewer = $staff('Lifecycle reviewer');
    $signIn = static fn (User $user) => Sanctum::actingAs($user, ['hotel:read', 'hotel:write', 'booking:write']);
    $signIn($recorder);
    $base = '/api/v1/hotels/'.$hotel->id;
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'QA-101']);
    $guest = $this->postJson($base.'/guests', ['firstName' => 'Synthetic', 'lastName' => 'Lifecycle'])->assertCreated()->json('data.id');
    $rate = $this->postJson($base.'/rate-plans', ['roomTypeId' => $type->id, 'name' => 'QA VAT inclusive', 'nightlyMinor' => 112000])
        ->assertCreated()->json('data.id');
    $this->postJson($base.'/finance/business-day/initialize', ['businessDate' => '2026-11-17'])->assertCreated();
    $policy = DB::table('financial_tax_policies')->insertGetId([
        'hotel_id' => $hotel->id, 'classification' => 'vat_standard', 'price_mode' => 'inclusive',
        'rate_bps' => 1200, 'effective_from' => '2026-11-17', 'status' => 'approved',
        'approved_by' => $reviewer->id, 'approved_at' => now(),
    ]);
    $booking = $this->postJson($base.'/bookings', [
        'idempotencyKey' => 'production-lifecycle-booking-1', 'guestId' => $guest, 'roomTypeId' => $type->id,
        'ratePlanId' => $rate, 'checkIn' => '2026-11-17', 'checkOut' => '2026-11-18',
    ])->assertCreated()->json('data.id');
    $path = $base.'/bookings/'.$booking;
    $this->patchJson($path.'/assign-room', ['roomId' => $room->id])->assertOk();
    $this->patchJson($base.'/readiness/enforcement-policy', [
        'expectedRevision' => 0, 'enforceAtCheckIn' => true, 'reason' => 'Synthetic mandatory readiness acceptance',
    ])->assertOk();
    $this->postJson($path.'/check-in')->assertStatus(409);
    $inspection = ['cleaningVerified' => true, 'safetyVerified' => true, 'amenitiesVerified' => true,
        'note' => 'Synthetic physical room checks completed.'];
    $this->postJson($base.'/rooms/'.$room->id.'/readiness/inspections', $inspection)->assertCreated();
    $this->postJson($path.'/check-in')->assertOk()->assertJsonPath('data.status', 'checked_in');
    $this->getJson($base.'/room-inventory-status?room_ids='.$room->id)->assertOk()->assertJsonPath('data.0.occupancy', 'occupied');

    $entry = $this->getJson($path.'/folio-night-entries')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
    $assessment = $this->postJson($path.'/folio-night-assessments', ['folioEntryId' => $entry, 'taxPolicyId' => $policy,
        'benefitKind' => 'none', 'eligibleGrossMinor' => 0])->assertCreated()
        ->assertJsonPath('data.vatMinor', 12000)->assertJsonPath('data.roomRevenueMinor', 100000)->json('data.id');
    $assessmentPath = $path.'/folio-night-assessments/'.$assessment;
    $this->postJson($assessmentPath.'/approve', ['acknowledged' => true])->assertUnprocessable();
    $signIn($reviewer);
    $this->postJson($assessmentPath.'/approve', ['acknowledged' => true])->assertOk()->assertJsonPath('data.status', 'approved');
    $journal = $this->postJson($assessmentPath.'/recognize', ['acknowledged' => true])->assertOk()->json('data.journalId');
    $this->postJson($assessmentPath.'/recognize', ['acknowledged' => true])->assertOk()->assertJsonPath('data.journalId', $journal);
    $this->assertDatabaseHas('financial_posting_lines', ['journal_id' => $journal, 'account_code' => 'ROOM_REV', 'credit_minor' => 100000]);

    $signIn($recorder);
    $payment = $this->postJson($path.'/payment-records', ['requestKey' => 'production-lifecycle-payment-1',
        'method' => 'bank_transfer', 'reference' => 'SYNTHETIC-BANK-001', 'amountMinor' => 112000])
        ->assertCreated()->json('data.id');
    $review = ['decision' => 'confirm', 'note' => 'Synthetic payment independently matched.'];
    $this->postJson($path.'/payment-records/'.$payment.'/review', $review)->assertForbidden();
    $signIn($reviewer);
    $this->postJson($path.'/payment-records/'.$payment.'/review', $review)->assertOk();
    $signIn($recorder);
    $verification = $this->postJson($path.'/payment-records/'.$payment.'/verification',
        ['evidenceKind' => 'bank_statement', 'evidenceReference' => 'QA-STATEMENT-001'])->assertCreated()->json('data.id');
    $verificationPath = $path.'/settlement-verifications/'.$verification.'/review';
    $evidence = ['decision' => 'verify', 'note' => 'Synthetic bank evidence independently reviewed.'];
    $this->postJson($verificationPath, $evidence)->assertUnprocessable();
    $signIn($reviewer);
    $this->postJson($verificationPath, $evidence)->assertOk()->assertJsonPath('data.status', 'verified');
    $allocation = ['requestKey' => 'production-lifecycle-allocation-1', 'payerKind' => 'guest', 'payerReference' => 'guest-'.$guest,
        'folioEntryId' => $entry, 'amountMinor' => 112000];
    $allocationPath = $path.'/payment-records/'.$payment.'/allocations';
    $this->postJson($allocationPath, $allocation)->assertCreated();
    $this->postJson($allocationPath, $allocation)->assertOk()->assertJsonPath('data.replayed', true);

    $this->travelTo(new DateTimeImmutable('2026-11-18T12:00:00+08:00'));
    $this->getJson($path.'/settlement-statement')->assertOk()->assertJsonPath('data.remainingMinor', 0)
        ->assertJsonPath('data.canSignOff', true);
    $this->postJson($path.'/settlement-signoff', ['acknowledged' => true])->assertOk();
    $this->postJson($path.'/check-out')->assertOk()->assertJsonPath('data.status', 'checked_out');
    $this->postJson($path.'/check-out')->assertStatus(409);
    $this->getJson($path.'/settlement-statement')->assertOk()->assertJsonPath('data.signedSnapshotIntegrity', true);
    $inventory = $base.'/room-inventory-status?room_ids='.$room->id;
    $task = $this->getJson($inventory)->assertOk()->assertJsonPath('data.0.cleaning', 'checkout_cleaning')
        ->json('data.0.turnoverTaskId');
    $this->patchJson($base.'/housekeeping-tasks/'.$task, ['expectedRevision' => 1, 'status' => 'in_progress', 'note' => 'Synthetic cleaning started.'])->assertOk();
    $this->patchJson($base.'/housekeeping-tasks/'.$task, ['expectedRevision' => 2, 'status' => 'done', 'note' => 'Synthetic cleaning completed.'])->assertOk();
    $this->getJson($inventory)->assertOk()->assertJsonPath('data.0.advisoryReady', false);
    $this->postJson($base.'/rooms/'.$room->id.'/readiness/inspections', $inspection)->assertCreated();
    $this->getJson($inventory)->assertOk()->assertJsonPath('data.0.occupancy', 'vacant')->assertJsonPath('data.0.advisoryReady', true);
    $this->assertDatabaseCount('folio_payment_records', 1);
    $this->assertDatabaseCount('folio_settlement_allocations', 1);
    $this->assertDatabaseCount('folio_settlement_signoffs', 1);
    $this->assertDatabaseCount('financial_room_revenue_recognitions', 1);
    $this->assertDatabaseCount('reservation_turnover_links', 1);
    $report = app(\App\Services\Finance\FinancialIntegrityDiagnostics::class)->report($hotel);
    expect($report['integrityValid'])->toBeTrue();
});

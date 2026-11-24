<?php

declare(strict_types=1);

use App\Domain\Reservations\CashierShiftService;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use App\Services\Finance\EmployeePayableReport;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;


it('restricts claim access and reconciles employee liability and drawer cash across business dates', function (): void {
    config(['financial_accounting.enable_employee_reimbursements' => true]);
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(12, 0));
    $hotel = Hotel::create(['name' => 'Reimbursement QA', 'currency' => 'PHP']);
    $staff = [];
    foreach (['admin', 'housekeeping', 'maintenance'] as $role) {
        $user = User::create(['name' => $role, 'email' => $role.'@example.test', 'password' => 'synthetic-password', 'is_active' => true]);
        HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        $staff[$role] = $user;
    }
    $manager = $staff['admin'];
    $employee = $staff['housekeeping'];
    $other = $staff['maintenance'];
    $base = "/api/v1/hotels/{$hotel->id}/employee-reimbursements";
    $approvalDate = '2026-11-23';
    $paymentDate = '2026-11-24';
    DB::table('financial_business_clocks')->insert(['hotel_id' => $hotel->id, 'business_date' => $approvalDate, 'status' => 'open']);
    DB::table('financial_accounting_periods')->insert([
        'hotel_id' => $hotel->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'open',
    ]);

    $input = [
        'requestKey' => 'claim-qa-65', 'merchant' => 'Hotel supplies', 'description' => 'Cleaning supplies',
        'department' => 'rooms', 'category' => 'supplies', 'receiptReference' => 'QA-65',
        'serviceDate' => $approvalDate, 'amountMinor' => 12000,
    ];
    Sanctum::actingAs($employee, ['hotel:read']);
    $this->postJson($base, $input)->assertForbidden();
    Sanctum::actingAs($employee, ['hotel:read', 'reimbursement:submit']);
    $claim = $this->postJson($base, $input)->assertCreated()->json('data.claimId');
    $this->getJson($base)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("$base/$claim")->assertOk()->assertJsonPath('data.status', 'pending');
    $approval = ['requestKey' => 'review-qa-65', 'decision' => 'approve', 'note' => 'Receipt checked'];
    $this->postJson("$base/$claim/decision", $approval)->assertForbidden();
    $this->postJson("$base/$claim/payments", [])->assertForbidden();

    Sanctum::actingAs($other, ['hotel:read', 'reimbursement:submit']);
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("$base/$claim")->assertNotFound();
    $otherHotel = Hotel::create(['name' => 'Other reimbursement property']);
    $this->getJson("/api/v1/hotels/{$otherHotel->id}/employee-reimbursements/$claim")->assertNotFound();
    HotelMembership::where('hotel_id', $hotel->id)->where('user_id', $other->id)
        ->firstOrFail()->update(['permission_overrides' => []]);
    $this->getJson($base)->assertForbidden();

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write', 'reimbursement:submit']);
    $this->getJson($base)->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("$base/$claim/decision", $approval)->assertCreated();
    DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => $paymentDate]);
    $shifts = app(CashierShiftService::class);
    $shift = $shifts->open($hotel, $manager->id, 20000);
    $drawerPayment = [
        'requestKey' => 'drawer-qa-65', 'paymentSource' => 'cashier_drawer',
        'shiftId' => $shift['id'], 'amountMinor' => 5000, 'evidenceReference' => 'DRAWER-QA-65',
    ];
    $this->postJson("$base/$claim/payments", $drawerPayment)->assertCreated();
    $this->postJson("$base/$claim/payments", $drawerPayment)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson("$base/$claim/payments", [
        'requestKey' => 'bank-qa-65', 'paymentSource' => 'hotel_bank',
        'amountMinor' => 2000, 'evidenceReference' => 'BANK-QA-65',
    ])->assertCreated();

    $closed = $shifts->close($hotel, $manager->id, (int) $shift['id'], 15000, null);
    expect($closed['expectedCashMinor'])->toBe(15000)
        ->and($closed['varianceMinor'])->toBe(0)
        ->and($closed['employeeReimbursementTotalMinor'])->toBe(5000)
        ->and($closed['employeeReimbursementCount'])->toBe(1);

    $report = app(EmployeePayableReport::class);
    $beforePayment = $report->report($hotel->id, $approvalDate);
    $afterPayment = $report->report($hotel->id, $paymentDate);
    expect($beforePayment['outstandingMinor'])->toBe(12000)
        ->and($beforePayment['reconciled'])->toBeTrue()
        ->and($afterPayment['ledgerBalanceMinor'])->toBe(5000)
        ->and($afterPayment['outstandingMinor'])->toBe(5000)
        ->and($afterPayment['reconciled'])->toBeTrue();
});

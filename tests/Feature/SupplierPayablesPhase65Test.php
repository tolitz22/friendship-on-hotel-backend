<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use App\Domain\Reservations\CashierShiftService;
use App\Services\Finance\DailyExpenseBreakdown;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function supplier65User(Hotel $hotel, string $role): User
{
    $user = User::create(['name' => 'Supplier '.$role,
        'email' => uniqid('supplier65-', true).'@example.test', 'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true]);
    return $user;
}

it('protects supplier management and preserves inactive suppliers', function (): void {
    $hotel = Hotel::create(['name' => 'Supplier directory', 'currency' => 'PHP']);
    $admin = supplier65User($hotel, 'admin');
    $cashier = supplier65User($hotel, 'front_desk');
    $path = '/api/v1/hotels/'.$hotel->id.'/suppliers';
    $input = ['name' => 'Fresh Linen', 'paymentTermsDays' => 30, 'isActive' => true];
    Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $this->getJson($path)->assertForbidden();
    $this->postJson($path, $input)->assertForbidden();
    Sanctum::actingAs($admin, ['hotel:read']);
    $this->postJson($path, $input)->assertForbidden();
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $supplier = $this->postJson($path, $input)->assertCreated()->json('data');
    $this->postJson($path, array_replace($input, ['name' => 'fresh   LINEN']))->assertStatus(409);
    $this->getJson($path.'?search=Linen')->assertOk()->assertJsonCount(1, 'data');
    $this->putJson($path.'/'.$supplier['id'], $input + ['version' => str_repeat('0', 64)])->assertStatus(409);
    $this->putJson($path.'/'.$supplier['id'], array_replace($input, ['isActive' => false, 'version' => $supplier['version']]))
        ->assertOk()->assertJsonPath('data.isActive', false);
    $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($path.'?status=inactive')->assertOk()->assertJsonCount(1, 'data');
    $other = Hotel::create(['name' => 'Other supplier hotel', 'currency' => 'PHP']);
    HotelMembership::create(['hotel_id' => $other->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    $this->getJson('/api/v1/hotels/'.$other->id.'/suppliers/'.$supplier['id'])->assertNotFound();
    $this->assertDatabaseCount('hotel_suppliers', 1);
});

it('posts invoices and settles them once while preserving historical payable and drawer balances', function (): void {
    $hotel = Hotel::create(['name' => 'Supplier payables', 'currency' => 'PHP']);
    $admin = supplier65User($hotel, 'admin');
    $reviewer = supplier65User($hotel, 'admin');
    $base = '/api/v1/hotels/'.$hotel->id;
    $path = $base.'/supplier-bills';
    DB::table('financial_business_clocks')->insert(['hotel_id' => $hotel->id, 'business_date' => '2026-11-24', 'status' => 'open']);
    DB::table('financial_accounting_periods')->insert(['hotel_id' => $hotel->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'open']);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $supplier = $this->postJson($base.'/suppliers', ['name' => 'Laundry', 'paymentTermsDays' => 0, 'isActive' => true])->assertCreated()->json('data.id');
    $invoice = ['requestKey' => 'supplier-bill-65', 'supplierId' => $supplier, 'invoiceReference' => 'INV-65',
        'description' => 'Laundry services', 'department' => 'rooms', 'category' => 'laundry',
        'invoiceDate' => '2026-11-24', 'serviceDate' => '2026-11-24', 'dueDate' => '2026-11-24', 'amountMinor' => 480000];
    config(['financial_accounting.enable_supplier_billing' => false]);
    $this->postJson($path, $invoice)->assertStatus(409);
    config(['financial_accounting.enable_supplier_billing' => true]);
    $posted = $this->postJson($path, $invoice)->assertCreated()->json('data');
    $bill = $posted['billId'];
    $this->postJson($path, $invoice)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson($path, array_replace($invoice, ['requestKey' => 'supplier-duplicate-65', 'invoiceReference' => 'inv-65']))->assertStatus(409);
    $this->assertDatabaseHas('financial_posting_lines', ['journal_id' => $posted['journalId'], 'account_code' => 'accounts_payable', 'credit_minor' => 480000]);
    $this->getJson($path)->assertOk()->assertJsonPath('summary.outstandingMinor', 480000);
    $this->getJson($path.'/aging')->assertOk()->assertJsonPath('data.buckets.current', 480000)->assertJsonPath('data.reconciled', true);
    DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => '2026-11-25']);
    $shift = (int) app(CashierShiftService::class)->open($hotel, $admin->id, 500000)['id'];
    $payment = ['requestKey' => 'supplier-payment-65', 'amountMinor' => 200000,
        'paymentSource' => 'cashier_drawer', 'shiftId' => $shift, 'evidenceReference' => 'CASH-65'];
    $payPath = $path.'/'.$bill.'/payments';
    $paid = $this->postJson($payPath, $payment)->assertCreated()->assertJsonPath('data.outstandingMinor', 280000)->json('data');
    $this->postJson($payPath, $payment)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson($payPath, array_replace($payment, ['requestKey' => 'supplier-overpay-65', 'amountMinor' => 280001]))->assertStatus(409);
    $this->postJson($payPath, array_replace($payment, ['requestKey' => 'supplier-duplicate-pay-65']))->assertStatus(409);
    $this->assertDatabaseHas('financial_posting_lines', ['journal_id' => $paid['journalId'], 'account_code' => 'cashier_cash', 'credit_minor' => 200000]);
    $this->getJson($path.'/aging?date=2026-11-24')->assertOk()->assertJsonPath('data.outstandingMinor', 480000);
    $this->getJson($path.'/aging')->assertOk()->assertJsonPath('data.buckets.days1To30', 280000)->assertJsonPath('data.reconciled', true);
    $shifts = app(CashierShiftService::class);
    $closed = $shifts->close($hotel, $admin->id, $shift, 300000, null);
    expect($closed['expectedCashMinor'])->toBe(300000)->and($closed['varianceMinor'])->toBe(0)
        ->and($closed['supplierPaymentTotalMinor'])->toBe(200000);
    expect($shifts->reconcile($hotel, $reviewer->id, $shift, 'reconcile', 'Independent count checked')['status'])->toBe('reconciled');
    $this->postJson($payPath, array_replace($payment, ['requestKey' => 'supplier-closed-drawer', 'evidenceReference' => 'CASH-66']))->assertStatus(409);
    $bank = ['requestKey' => 'supplier-bank-65', 'amountMinor' => 280000, 'paymentSource' => 'hotel_bank', 'evidenceReference' => 'BANK-65'];
    $this->postJson($payPath, $bank)->assertCreated()->assertJsonPath('data.paymentStatus', 'paid');
    $this->getJson($path.'/'.$bill)->assertOk()->assertJsonCount(2, 'data.payments')->assertJsonPath('data.outstandingMinor', 0);
    $this->getJson($path.'/aging')->assertOk()->assertJsonPath('data.ledgerBalanceMinor', 0)->assertJsonPath('data.reconciled', true);
    $breakdown = app(DailyExpenseBreakdown::class);
    expect($breakdown->report($hotel->id, '2026-11-24', 480000)['unattributedNetMinor'])->toBe(0);
    expect($breakdown->report($hotel->id, '2026-11-25', 0)['movementCount'])->toBe(0);
    $other = Hotel::create(['name' => 'Other payable hotel', 'currency' => 'PHP']);
    HotelMembership::create(['hotel_id' => $other->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    $this->getJson('/api/v1/hotels/'.$other->id.'/supplier-bills/'.$bill)->assertNotFound();
    $this->assertDatabaseCount('supplier_bills', 1);
    $this->assertDatabaseCount('supplier_bill_payments', 2);
    $this->assertDatabaseCount('financial_posting_journals', 3);
    $this->assertDatabaseCount('folio_payment_records', 0);
});

<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use App\Domain\Reservations\CashierShiftService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function expenseApi65User(Hotel $hotel, string $role): User
{
    $user = User::create(['name' => 'Expense API '.$role,
        'email' => uniqid('expense-api-', true).'@example.test', 'password' => 'Password123456!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true]);
    return $user;
}

it('protects expense routes and records an authorized payout only when enabled', function (): void {
    $hotel = Hotel::create(['name' => 'Expense API', 'currency' => 'PHP']);
    $cashier = expenseApi65User($hotel, 'front_desk');
    $manager = expenseApi65User($hotel, 'admin');
    $cleaner = expenseApi65User($hotel, 'housekeeping');
    $base = '/api/v1/hotels/'.$hotel->id;
    $shift = app(CashierShiftService::class)->open($hotel, $cashier->id, 500000)['id'];
    $path = $base.'/cashier-shifts/'.$shift.'/expense-payouts';
    DB::table('financial_business_clocks')->insert([
        'hotel_id' => $hotel->id, 'business_date' => '2026-11-24', 'status' => 'open',
    ]);
    DB::table('financial_accounting_periods')->insert([
        'hotel_id' => $hotel->id, 'starts_on' => '2026-11-01',
        'ends_on' => '2026-11-30', 'status' => 'open',
    ]);
    $payload = ['requestKey' => 'api-expense-65', 'payee' => 'Supply Shop',
        'description' => 'Cleaning supplies', 'department' => 'rooms',
        'category' => 'cleaning_supplies', 'serviceDate' => '2026-11-24', 'amountMinor' => 35000];
    config(['financial_accounting.enable_expense_posting' => false]);
    Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $this->postJson($path, $payload)->assertStatus(409);
    $this->getJson($base.'/expenses')->assertForbidden();
    $this->putJson($base.'/expenses/policy', ['routineLimitMinor' => 0, 'reason' => 'Set limit'])->assertForbidden();

    config(['financial_accounting.enable_expense_posting' => true]);
    Sanctum::actingAs($cleaner, ['hotel:read', 'booking:write']);
    $this->postJson($path, $payload)->assertForbidden();
    Sanctum::actingAs($cashier, ['hotel:read']);
    $this->postJson($path, $payload)->assertForbidden();

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->putJson($base.'/expenses/policy', ['routineLimitMinor' => 10000, 'reason' => 'Test limit'])->assertOk();
    $this->getJson($base.'/expenses/policy')->assertOk()->assertJsonPath('data.routineLimitMinor', 10000);
    Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $expense = $this->postJson($path, $payload)->assertCreated()->json('data.expenseId');
    $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson($base.'/expenses/'.$expense.'/review', ['decision' => 'approve', 'note' => 'Checked invoice'])->assertForbidden();

    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->getJson($base.'/expenses?department=rooms&search=Supply')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('summary.monthExpenseMinor', 35000)
        ->assertJsonPath('summary.pendingReviewCount', 1);
    $this->getJson($base.'/expenses/'.$expense)->assertOk()->assertJsonPath('data.recordedByName', $cashier->name);
    $this->postJson($base.'/expenses/'.$expense.'/review', ['decision' => 'approve', 'note' => 'Checked invoice'])
        ->assertOk()->assertJsonPath('data.reviewStatus', 'approved');
    $this->getJson($base.'/expenses/'.$expense)->assertOk()->assertJsonPath('data.review.reviewedByName', $manager->name);

    $other = Hotel::create(['name' => 'Other Expense Property', 'currency' => 'PHP']);
    HotelMembership::create(['hotel_id' => $other->id, 'user_id' => $manager->id,
        'role' => 'admin', 'is_active' => true]);
    $otherBase = '/api/v1/hotels/'.$other->id;
    $this->getJson($otherBase.'/expenses')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($otherBase.'/expenses/'.$expense)->assertNotFound();
    $this->postJson($otherBase.'/expenses/'.$expense.'/review', ['decision' => 'approve', 'note' => 'Checked invoice'])->assertNotFound();
    $this->assertDatabaseCount('cashier_expense_payouts', 1);
});

it('records already-paid hotel bank and card expenses once without touching a drawer', function (string $source): void {
    $hotel = Hotel::create(['name' => 'Direct Expense', 'currency' => 'PHP']);
    $manager = expenseApi65User($hotel, 'admin');
    $cashier = expenseApi65User($hotel, 'front_desk');
    $path = '/api/v1/hotels/'.$hotel->id.'/expenses';
    DB::table('financial_business_clocks')->insert([
        'hotel_id' => $hotel->id, 'business_date' => '2026-11-24', 'status' => 'open',
    ]);
    DB::table('financial_accounting_periods')->insert([
        'hotel_id' => $hotel->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'open',
    ]);
    $payload = ['requestKey' => 'api-direct-expense-65', 'payee' => 'Utility Company',
        'description' => 'Electricity', 'department' => 'utilities', 'category' => 'utilities',
        'serviceDate' => '2026-11-23', 'amountMinor' => 35000, 'paymentSource' => $source,
        'paymentReference' => 'PAY-20261123'];
    config(['financial_accounting.enable_expense_posting' => true]);

    Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $this->postJson($path, $payload)->assertForbidden();
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->postJson($path, array_diff_key($payload, ['paymentReference' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors('paymentReference');

    $result = $this->postJson($path, $payload)->assertCreated()->json('data');
    $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson($path, array_replace($payload, ['amountMinor' => 45000]))->assertConflict();
    $this->getJson($path.'/'.$result['expenseId'])->assertOk()
        ->assertJsonPath('data.paymentSource', $source)->assertJsonPath('data.shiftId', null)
        ->assertJsonPath('data.paymentStatus', 'paid')->assertJsonPath('data.paymentReference', 'PAY-20261123');
    $this->assertDatabaseHas('financial_posting_lines', [
        'journal_id' => $result['journalId'], 'account_code' => $source, 'credit_minor' => 35000,
    ]);
    $this->assertDatabaseCount('hotel_expenses', 1);
    $this->assertDatabaseCount('cashier_expense_payouts', 0);
})->with(['hotel_bank', 'hotel_card']);

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

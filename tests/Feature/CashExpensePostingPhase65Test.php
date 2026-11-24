<?php

declare(strict_types=1);

use App\Domain\Reservations\CashierShiftService;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Finance\CashExpensePostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function expense65Fixture(bool $clock = true): array
{
    $hotel = Hotel::create(['name' => 'Expense 65', 'currency' => 'PHP']);
    $cashier = User::create(['name' => 'Expense Cashier', 'email' => uniqid('expense-', true).'@example.test', 'password' => 'Password123456!']);
    $shifts = app(CashierShiftService::class);
    $shift = (int) $shifts->open($hotel, $cashier->id, 500000)['id'];
    if ($clock) {
        DB::table('financial_business_clocks')->insert([
            'hotel_id' => $hotel->id, 'business_date' => '2026-11-24', 'status' => 'open',
        ]);
        DB::table('financial_accounting_periods')->insert([
            'hotel_id' => $hotel->id, 'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30', 'status' => 'open',
        ]);
    }
    $input = [
        'requestKey' => 'expense-65-supplies', 'payee' => 'Supply Store',
        'description' => 'Cleaning supplies', 'department' => 'rooms',
        'category' => 'cleaning_supplies', 'serviceDate' => '2026-11-23',
        'amountMinor' => 35000,
    ];
    return [$hotel, $cashier, $shift, $input];
}

it('posts one balanced expense and deducts the payout exactly once from the drawer', function (): void {
    [$hotel, $cashier, $shift, $input] = expense65Fixture();
    $service = app(CashExpensePostingService::class);
    $shifts = app(CashierShiftService::class);
    $first = $service->record($hotel, $cashier->id, $shift, $input);
    $retry = $service->record($hotel, $cashier->id, $shift, $input);
    expect($first['replayed'])->toBeFalse()
        ->and($retry['replayed'])->toBeTrue()
        ->and($retry['expenseId'])->toBe($first['expenseId']);
    $this->assertDatabaseCount('hotel_expenses', 1);
    $this->assertDatabaseCount('cashier_expense_payouts', 1);
    $this->assertDatabaseCount('financial_posting_journals', 1);
    $this->assertDatabaseCount('financial_posting_lines', 2);
    $this->assertDatabaseCount('folio_payment_records', 0);
    $this->assertDatabaseHas('hotel_expenses', [
        'id' => $first['expenseId'], 'service_date' => '2026-11-23',
        'business_date' => '2026-11-24',
    ]);
    $this->assertDatabaseHas('financial_posting_lines', [
        'journal_id' => $first['journalId'], 'account_code' => 'operating_expense',
        'debit_minor' => 35000, 'credit_minor' => 0,
    ]);
    $this->assertDatabaseHas('financial_posting_lines', [
        'journal_id' => $first['journalId'], 'account_code' => 'cashier_cash',
        'debit_minor' => 0, 'credit_minor' => 35000,
    ]);
    $open = $shifts->details($hotel, $cashier->id, false, $shift);
    expect($open['expectedCashMinor'])->toBeNull()
        ->and($open['expensePayoutTotalMinor'])->toBe(35000);
    $closed = $shifts->close($hotel, $cashier->id, $shift, 465000, null);
    expect($closed['expectedCashMinor'])->toBe(465000)
        ->and($closed['varianceMinor'])->toBe(0)
        ->and($closed['expensePayoutCount'])->toBe(1)
        ->and($closed['expensePayouts'][0]['expenseId'])->toBe($first['expenseId']);
    expect($service->record($hotel, $cashier->id, $shift, $input)['replayed'])->toBeTrue();
    $this->assertDatabaseCount('cashier_expense_payouts', 1);
});

it('rejects changed retries, foreign drawers, and new payouts after shift submission', function (): void {
    [$hotel, $cashier, $shift, $input] = expense65Fixture();
    $service = app(CashExpensePostingService::class);
    $service->record($hotel, $cashier->id, $shift, $input);
    $changed = array_replace($input, ['amountMinor' => 36000]);
    expect(fn () => $service->record($hotel, $cashier->id, $shift, $changed))
        ->toThrow(HttpException::class);
    $other = Hotel::create(['name' => 'Other property', 'currency' => 'PHP']);
    expect(fn () => $service->record($other, $cashier->id, $shift, $input))
        ->toThrow(HttpException::class);
    app(CashierShiftService::class)->close($hotel, $cashier->id, $shift, 465000, null);
    $new = array_replace($input, ['requestKey' => 'expense-65-new']);
    expect(fn () => $service->record($hotel, $cashier->id, $shift, $new))
        ->toThrow(HttpException::class);
    $this->assertDatabaseCount('hotel_expenses', 1);
    $this->assertDatabaseCount('cashier_expense_payouts', 1);
});

it('leaves no expense or payout when the financial clock is missing', function (): void {
    [$hotel, $cashier, $shift, $input] = expense65Fixture(false);
    expect(fn () => app(CashExpensePostingService::class)->record($hotel, $cashier->id, $shift, $input))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('hotel_expenses', 0);
    $this->assertDatabaseCount('cashier_expense_payouts', 0);
    $this->assertDatabaseCount('financial_posting_journals', 0);
    $this->assertDatabaseCount('financial_posting_lines', 0);
    $closed = app(CashierShiftService::class)->close($hotel, $cashier->id, $shift, 500000, null);
    expect($closed['varianceMinor'])->toBe(0);
});

it('snapshots routine limits and requires an independent manager for above-limit cash', function (): void {
    [$hotel, $cashier, $shift, $input] = expense65Fixture();
    $manager = User::create(['name' => 'Expense Manager', 'email' => 'manager65@example.test', 'password' => 'Password123456!']);
    foreach ([$cashier, $manager] as $user) {
        \App\Models\HotelMembership::create([
            'hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true,
        ]);
    }
    $policy = app(\App\Services\Finance\ExpensePolicyService::class);
    $posting = app(CashExpensePostingService::class);
    $policy->update($hotel, $manager->id, ['routineLimitMinor' => 35000, 'reason' => 'Initial limit']);
    $routine = $posting->record($hotel, $cashier->id, $shift, $input);
    $this->assertDatabaseHas('hotel_expenses', ['id' => $routine['expenseId'], 'review_status' => 'not_required', 'policy_limit_minor' => 35000]);
    $exception = $posting->record($hotel, $cashier->id, $shift, array_replace($input, [
        'requestKey' => 'expense-65-exception', 'amountMinor' => 36000,
    ]));
    $id = (int) $exception['expenseId'];
    $review = ['decision' => 'approve', 'note' => 'Invoice inspected'];
    expect(fn () => $policy->review($hotel, $cashier->id, $id, $review))->toThrow(HttpException::class);
    $outsider = User::create(['name' => 'Other Staff', 'email' => 'other65@example.test', 'password' => 'Password123456!']);
    expect(fn () => $policy->review($hotel, $outsider->id, $id, $review))->toThrow(HttpException::class);
    expect($policy->review($hotel, $manager->id, $id, $review)['reviewStatus'])->toBe('approved');
    expect($policy->review($hotel, $manager->id, $id, $review)['replayed'])->toBeTrue();
    $this->assertDatabaseCount('hotel_expense_reviews', 1);
    $this->assertDatabaseCount('financial_posting_journals', 2);
    $this->assertDatabaseCount('cashier_expense_payouts', 2);
    $closed = app(CashierShiftService::class)->close($hotel, $cashier->id, $shift, 429000, null);
    expect($closed['varianceMinor'])->toBe(0);
});

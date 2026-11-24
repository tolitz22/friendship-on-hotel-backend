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
    $cashier = User::factory()->create();
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

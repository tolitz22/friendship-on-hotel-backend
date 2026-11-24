<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use App\Domain\Reservations\CashierShiftService;
use App\Services\Finance\CashExpensePostingService;
use App\Services\Finance\ExpenseCorrectionService;
use App\Services\Finance\ExpenseCashReturnService;
use App\Services\Finance\DailyExpenseBreakdown;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

function correction65Fixture(): array
{
    $hotel = Hotel::create(['name' => 'Correction fixture', 'currency' => 'PHP']);
    $users = [];
    foreach (['front_desk', 'admin', 'admin'] as $role) {
        $user = User::create(['name' => 'Correction '.$role, 'email' => uniqid('correction-', true).'@example.test', 'password' => 'Password123456!']);
        HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        $users[] = $user;
    }
    [$cashier, $requester, $reviewer] = $users;
    $shift = (int) app(CashierShiftService::class)->open($hotel, $cashier->id, 500000)['id'];
    DB::table('financial_business_clocks')->insert(['hotel_id' => $hotel->id, 'business_date' => '2026-11-24', 'status' => 'open']);
    DB::table('financial_accounting_periods')->insert(['hotel_id' => $hotel->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'open']);
    $posted = app(CashExpensePostingService::class)->record($hotel, $cashier->id, $shift, [
        'requestKey' => 'correction-fixture-original', 'payee' => 'Supply shop', 'description' => 'Cleaning supplies',
        'department' => 'rooms', 'category' => 'cleaning_supplies', 'serviceDate' => '2026-11-24', 'amountMinor' => 50000,
    ]);
    return [$hotel, $cashier, $requester, $reviewer, $shift, (int) $posted['expenseId']];
}

it('keeps original cash and historical department movements intact when approving a correction and returning cash', function (): void {
    [$hotel, $cashier, $requester, $reviewer, $shift, $expense] = correction65Fixture();
    $corrections = app(ExpenseCorrectionService::class);
    $returns = app(ExpenseCashReturnService::class);
    $shifts = app(CashierShiftService::class);
    $breakdown = app(DailyExpenseBreakdown::class);
    $original = $shifts->close($hotel, $cashier->id, $shift, 450000, null);
    expect($original['varianceMinor'])->toBe(0);
    $shifts->reconcile($hotel, $reviewer->id, $shift, 'reconcile', 'Count independently verified');
    // Move the clock forward to exercise posting-date attribution.
    DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => '2026-11-25']);
    $next = (int) $shifts->open($hotel, $cashier->id, 100000)['id'];
    $payload = ['requestKey' => 'correction-request-65', 'kind' => 'amount_correction', 'correctedAmountMinor' => 35000, 'reason' => 'Original amount overstated'];
    $id = (int) $corrections->request($hotel, $requester->id, $expense, $payload)['correctionId'];
    expect($corrections->request($hotel, $requester->id, $expense, $payload)['replayed'])->toBeTrue();
    $decision = ['decision' => 'approve', 'note' => 'Supplier receipt checked'];
    expect(fn () => $corrections->decide($hotel, $requester->id, $id, $decision))->toThrow(HttpException::class);
    $approved = $corrections->decide($hotel, $reviewer->id, $id, $decision);
    expect($corrections->decide($hotel, $reviewer->id, $id, $decision)['replayed'])->toBeTrue();
    $this->assertDatabaseHas('hotel_expenses', ['id' => $expense, 'amount_minor' => 50000]);
    $this->assertDatabaseHas('financial_posting_lines', ['journal_id' => $approved['journalId'], 'account_code' => 'expense_correction_clearing', 'debit_minor' => 15000, 'credit_minor' => 0]);
    $refund = ['requestKey' => 'correction-cash-return-65', 'amountMinor' => 10000, 'correctionId' => $id, 'evidenceReference' => 'RETURN-65', 'reason' => 'Supplier returned cash'];
    $first = $returns->record($hotel, $cashier->id, $expense, $next, $refund);
    expect($returns->record($hotel, $cashier->id, $expense, $next, $refund)['replayed'])->toBeTrue();
    expect(fn () => $returns->record($hotel, $cashier->id, $expense, $next, array_replace($refund, ['requestKey' => 'over-limit-return', 'amountMinor' => 5001])))->toThrow(HttpException::class);
    $second = $returns->record($hotel, $cashier->id, $expense, $next, array_replace($refund, ['requestKey' => 'remaining-return-65', 'amountMinor' => 5000, 'evidenceReference' => 'RETURN-66']));
    $this->assertDatabaseHas('financial_posting_lines', ['journal_id' => $first['journalId'], 'account_code' => 'expense_correction_clearing', 'credit_minor' => 10000, 'debit_minor' => 0]);
    $closed = $shifts->close($hotel, $cashier->id, $next, 115000, null);
    expect($closed['expectedCashMinor'])->toBe(115000)->and($closed['varianceMinor'])->toBe(0);
    expect(fn () => $shifts->reconcile($hotel, $reviewer->id, $next, 'reconcile', 'Review all returns'))->toThrow(HttpException::class);
    foreach ([$first, $second] as $return) {
        $returns->review($hotel, $reviewer->id, (int) $return['cashReturnId'], ['decision' => 'approve', 'note' => 'Evidence independently checked']);
    }
    expect($shifts->reconcile($hotel, $reviewer->id, $next, 'reconcile', 'All cash verified')['status'])->toBe('reconciled');
    expect($shifts->details($hotel, $cashier->id, false, $shift)['expectedCashMinor'])->toBe(450000);
    $oldReport = $breakdown->report($hotel->id, '2026-11-24', 50000);
    $newReport = $breakdown->report($hotel->id, '2026-11-25', -15000);
    expect($oldReport['departments'][0]['netMinor'])->toBe(50000)
        ->and($newReport['departments'][0]['netMinor'])->toBe(-15000)
        ->and($newReport['unattributedNetMinor'])->toBe(0);
    $this->assertDatabaseCount('expense_cash_returns', 2);
    $this->assertDatabaseCount('financial_posting_journals', 4);
    $this->assertDatabaseCount('folio_payment_records', 0);
});

it('blocks new cash returns to closed drawers and rejects new journals without an open financial date', function (): void {
    [$hotel, $cashier, $requester, $reviewer, $shift, $expense] = correction65Fixture();
    $returns = app(ExpenseCashReturnService::class);
    $payload = ['requestKey' => 'closed-return-65', 'amountMinor' => 1000, 'evidenceReference' => 'REF-65', 'reason' => 'Supplier cash returned'];
    DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['status' => 'closed']);
    expect(fn () => $returns->record($hotel, $cashier->id, $expense, $shift, $payload))->toThrow(ValidationException::class);
    app(CashierShiftService::class)->close($hotel, $cashier->id, $shift, 450000, null);
    expect(fn () => $returns->record($hotel, $cashier->id, $expense, $shift, $payload))->toThrow(HttpException::class);
    $this->assertDatabaseCount('expense_cash_returns', 0);
    $this->assertDatabaseCount('financial_posting_journals', 1);
});

it('records ordinary supplier refunds once and preserves signed negative drawer discrepancies', function (): void {
    [$hotel, $cashier, $requester, $reviewer, $shift, $expense] = correction65Fixture();
    $returns = app(ExpenseCashReturnService::class);
    $payload = ['requestKey' => 'ordinary-refund-65', 'amountMinor' => 5000, 'evidenceReference' => 'REF-65', 'reason' => 'Supplier cash refund'];
    $refund = $returns->record($hotel, $cashier->id, $expense, $shift, $payload);
    $this->assertDatabaseHas('financial_posting_lines', ['journal_id' => $refund['journalId'], 'account_code' => 'operating_expense', 'credit_minor' => 5000]);
    $report = app(DailyExpenseBreakdown::class)->report($hotel->id, '2026-11-24', 45000);
    expect($report['departments'][0]['netMinor'])->toBe(45000);
    app(CashExpensePostingService::class)->record($hotel, $cashier->id, $shift, [
        'requestKey' => 'large-payout-65', 'payee' => 'Supplier', 'description' => 'Maintenance supplies',
        'department' => 'maintenance', 'category' => 'repair_materials', 'serviceDate' => '2026-11-24', 'amountMinor' => 500000,
    ]);
    $closed = app(CashierShiftService::class)->close($hotel, $cashier->id, $shift, 0, null);
    expect($closed['expectedCashMinor'])->toBe(-45000)->and($closed['varianceMinor'])->toBe(45000);
});

it('lists current expense exceptions independently of historical dates and protects hotel boundaries', function (): void {
    [$hotel, $cashier, $requester, $reviewer, $shift, $expense] = correction65Fixture();
    $corrections = app(ExpenseCorrectionService::class);
    $returns = app(ExpenseCashReturnService::class);
    $path = '/api/v1/hotels/'.$hotel->id.'/expenses/exceptions';
    $id = (int) $corrections->request($hotel, $requester->id, $expense, [
        'requestKey' => 'queue-correction-65', 'kind' => 'amount_correction',
        'correctedAmountMinor' => 35000, 'reason' => 'Correct supplier amount',
    ])['correctionId'];
    \Laravel\Sanctum\Sanctum::actingAs($cashier, ['hotel:read', 'booking:write']);
    $this->getJson($path)->assertForbidden();
    \Laravel\Sanctum\Sanctum::actingAs($reviewer, ['hotel:read', 'hotel:write']);
    $this->getJson($path)->assertOk()->assertJsonPath('data.pendingCorrectionCount', 1)
        ->assertJsonPath('data.items.0.expenseId', (string) $expense);
    $corrections->decide($hotel, $reviewer->id, $id, ['decision' => 'approve', 'note' => 'Receipt verified']);
    $this->getJson($path)->assertOk()->assertJsonPath('data.pendingCorrectionCount', 0)
        ->assertJsonPath('data.unresolvedClearingCount', 1)->assertJsonPath('data.items.0.amountMinor', 15000);
    $refund = $returns->record($hotel, $cashier->id, $expense, $shift, [
        'requestKey' => 'queue-return-65', 'amountMinor' => 15000, 'correctionId' => $id,
        'evidenceReference' => 'QUEUE-REF', 'reason' => 'Physical cash returned',
    ]);
    $this->getJson($path)->assertOk()->assertJsonPath('data.unresolvedClearingCount', 0)
        ->assertJsonPath('data.pendingReturnCount', 1);
    $returns->review($hotel, $reviewer->id, (int) $refund['cashReturnId'], ['decision' => 'flag', 'note' => 'Evidence needs investigation']);
    $this->getJson($path)->assertOk()->assertJsonPath('data.pendingReturnCount', 0)
        ->assertJsonPath('data.flaggedReturnCount', 1);
    $other = Hotel::create(['name' => 'Separate exception hotel', 'currency' => 'PHP']);
    HotelMembership::create(['hotel_id' => $other->id, 'user_id' => $reviewer->id, 'role' => 'admin', 'is_active' => true]);
    $this->getJson('/api/v1/hotels/'.$other->id.'/expenses/exceptions')->assertOk()->assertJsonCount(0, 'data.items')
        ->assertJsonPath('data.flaggedReturnCount', 0);
});

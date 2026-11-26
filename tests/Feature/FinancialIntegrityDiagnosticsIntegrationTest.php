<?php

declare(strict_types=1);

use App\Domain\Reservations\CashierShiftService;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use App\Services\Finance\FinancialJournalPostingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function integrityQaHotel(string $name): array
{
    $hotel = Hotel::create(['name' => $name, 'currency' => 'PHP', 'timezone' => 'Asia/Manila']);
    $admin = User::create(['name' => 'Integrity QA admin', 'email' => uniqid('integrity-', true).'@example.test',
        'password' => 'SyntheticPassword123!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    DB::table('financial_business_clocks')->insert(['hotel_id' => $hotel->id, 'business_date' => '2026-11-24', 'status' => 'open']);
    DB::table('financial_accounting_periods')->insert(['hotel_id' => $hotel->id, 'starts_on' => '2026-11-01',
        'ends_on' => '2026-11-30', 'status' => 'open']);
    return [$hotel, $admin];
}

function integrityQaReport(Hotel $hotel, bool $repair = false): array
{
    $args = ['--hotel' => (string) $hotel->id];
    $exit = Artisan::call($repair ? 'pms:financial-repair' : 'pms:financial-integrity',
        $args + ($repair ? ['--dry-run' => true] : ['--format' => 'json']));
    return [$exit, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
}

function integrityQaSnapshot(): array
{
    $result = [];
    foreach (['financial_business_clocks', 'financial_day_closes', 'financial_accounting_periods',
        'financial_posting_journals', 'financial_posting_lines', 'cashier_shifts', 'cashier_shift_events',
        'folio_payment_records', 'folio_settlement_verifications', 'folio_settlement_allocations',
        'supplier_bills', 'supplier_bill_payments', 'employee_expense_claims',
        'employee_expense_claim_decisions', 'employee_reimbursement_payments'] as $table) {
        $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
    return $result;
}

it('diagnoses clean persisted records without changing financial history or claiming release certification', function (): void {
    [$hotel, $admin] = integrityQaHotel('Clean diagnostics');
    app(FinancialJournalPostingService::class)->post((int) $hotel->id, 'qa-clean', 'qa_fixture', 'qa-clean', 'Synthetic journal', [
        ['account_code' => 'AR', 'debit_minor' => 10000, 'credit_minor' => 0],
        ['account_code' => 'ROOM_REV', 'debit_minor' => 0, 'credit_minor' => 10000],
    ], (int) $admin->id);
    app(CashierShiftService::class)->open($hotel, (int) $admin->id, 10000);
    $before = integrityQaSnapshot();
    [$exit, $report] = integrityQaReport($hotel);
    expect($exit)->toBe(0)->and($report['integrityValid'])->toBeTrue()
        ->and($report['readOnly'])->toBeTrue()->and($report['releaseCertified'])->toBeFalse()
        ->and($report['closingCertified'])->toBeFalse()->and($report['scannedJournalCount'])->toBe(1)
        ->and($report['dailyClosing']['canClose'])->toBeFalse()
        ->and($report['metrics']['supplierPayable']['reconciled'])->toBeTrue()
        ->and($report['metrics']['employeePayable']['reconciled'])->toBeTrue();
    expect(integrityQaSnapshot())->toBe($before);
    [$exit, $preview] = integrityQaReport($hotel, true);
    expect($exit)->toBe(0)->and($preview['proposals'])->toBe([])->and($preview['recordsModified'])->toBeFalse();
    expect(integrityQaSnapshot())->toBe($before);
});

it('detects corrupted journals and liabilities only in the affected hotel and never repairs them', function (): void {
    [$hotel, $admin] = integrityQaHotel('Affected diagnostics');
    [$other] = integrityQaHotel('Isolated diagnostics');
    $journal = app(FinancialJournalPostingService::class)->post((int) $hotel->id, 'qa-bad', 'qa_fixture', 'qa-bad', 'Synthetic journal', [
        ['account_code' => 'expense', 'debit_minor' => 10000, 'credit_minor' => 0],
        ['account_code' => 'accounts_payable', 'debit_minor' => 0, 'credit_minor' => 10000],
    ], (int) $admin->id);
    DB::table('financial_posting_lines')->where('journal_id', $journal)->where('account_code', 'accounts_payable')
        ->update(['credit_minor' => 9999]);
    $before = integrityQaSnapshot();
    [$exit, $report] = integrityQaReport($hotel);
    expect($exit)->toBe(1)->and($report['integrityValid'])->toBeFalse()
        ->and(array_column($report['issues'], 'reason'))->toContain('invalid_or_unbalanced_journal', 'supplier_payable_not_reconciled');
    expect($report['metrics']['supplierPayable']['differenceMinor'])->toBe(9999);
    [$exit, $preview] = integrityQaReport($hotel, true);
    expect($exit)->toBe(1)->and($preview['recordsModified'])->toBeFalse();
    foreach ($preview['proposals'] as $proposal) {
        expect($proposal['approvalRequired'])->toBeTrue()->and($proposal['executable'])->toBeFalse();
    }
    [$exit, $isolated] = integrityQaReport($other);
    expect($exit)->toBe(0)->and($isolated['issues'])->toBe([])->and($isolated['scannedJournalCount'])->toBe(0);
    expect(integrityQaSnapshot())->toBe($before);
});

it('detects a changed submitted cashier count and refuses a repair without explicit dry run', function (): void {
    [$hotel, $admin] = integrityQaHotel('Drawer diagnostics');
    $service = app(CashierShiftService::class);
    $shift = (int) $service->open($hotel, (int) $admin->id, 10000)['id'];
    $service->close($hotel, (int) $admin->id, $shift, 10000, null);
    DB::table('cashier_shifts')->where('id', $shift)->update(['expected_cash_minor' => 9000]);
    $before = integrityQaSnapshot();
    [$exit, $report] = integrityQaReport($hotel);
    expect($exit)->toBe(1)->and(array_column($report['issues'], 'reason'))->toContain('cashier_count_snapshot_mismatch');
    expect(Artisan::call('pms:financial-repair', ['--hotel' => (string) $hotel->id]))->toBe(2);
    expect(integrityQaSnapshot())->toBe($before);
});

it('fails the financial readiness gate on operational blockers without declaring financial corruption', function (): void {
    [$hotel] = integrityQaHotel('Readiness gate');
    $before = integrityQaSnapshot();
    $exit = Artisan::call('pms:financial-readiness', ['--hotel' => (string) $hotel->id]);
    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(1)->and($report['readiness']['status'])->toBe('operational_blocked')
        ->and($report['readiness']['integrityValid'])->toBeTrue()
        ->and($report['readiness']['releaseCertified'])->toBeFalse();
    expect(integrityQaSnapshot())->toBe($before);
});

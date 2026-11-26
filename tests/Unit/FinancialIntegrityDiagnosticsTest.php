<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\FinancialIntegrityDiagnostics;
use Tests\TestCase;

final class FinancialIntegrityDiagnosticsTest extends TestCase
{
    public function test_balanced_journal_requires_two_positive_sides(): void
    {
        $debit = (object) ['account_code' => 'cash', 'debit_minor' => 100, 'credit_minor' => 0];
        $credit = (object) ['account_code' => 'revenue', 'debit_minor' => 0, 'credit_minor' => 100];
        self::assertTrue(FinancialIntegrityDiagnostics::balanced([$debit, $credit]));
        self::assertFalse(FinancialIntegrityDiagnostics::balanced([]));
        self::assertFalse(FinancialIntegrityDiagnostics::balanced([$debit]));
        $credit->credit_minor = 99;
        self::assertFalse(FinancialIntegrityDiagnostics::balanced([$debit, $credit]));
        $credit->credit_minor = 100;
        $credit->debit_minor = 100;
        self::assertFalse(FinancialIntegrityDiagnostics::balanced([$debit, $credit]));
    }

    public function test_commands_reject_missing_scope_or_dry_run(): void
    {
        $this->artisan('pms:financial-integrity')->assertExitCode(2);
        $this->artisan('pms:financial-integrity', ['--hotel' => '2', '--format' => 'xml'])->assertExitCode(2);
        $this->artisan('pms:financial-repair', ['--hotel' => '2'])->assertExitCode(2);
    }
}

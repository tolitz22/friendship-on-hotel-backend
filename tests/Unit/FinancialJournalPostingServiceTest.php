<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\FinancialJournalPostingService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FinancialJournalPostingServiceTest extends TestCase
{
    public static function invalidLines(): array
    {
        return [
            'no lines' => [[]],
            'single line' => [[['account_code' => 'CASH', 'debit_minor' => 100, 'credit_minor' => 0]]],
            'unbalanced' => [[
                ['account_code' => 'CASH', 'debit_minor' => 100, 'credit_minor' => 0],
                ['account_code' => 'REV', 'debit_minor' => 0, 'credit_minor' => 99],
            ]],
            'both sides' => [[
                ['account_code' => 'CASH', 'debit_minor' => 100, 'credit_minor' => 1],
                ['account_code' => 'REV', 'debit_minor' => 0, 'credit_minor' => 99],
            ]],
            'negative' => [[
                ['account_code' => 'CASH', 'debit_minor' => -100, 'credit_minor' => 0],
                ['account_code' => 'REV', 'debit_minor' => 0, 'credit_minor' => 100],
            ]],
            'zero line' => [[
                ['account_code' => 'CASH', 'debit_minor' => 100, 'credit_minor' => 0],
                ['account_code' => 'REV', 'debit_minor' => 0, 'credit_minor' => 100],
                ['account_code' => 'OTHER', 'debit_minor' => 0, 'credit_minor' => 0],
            ]],
            'overflow' => [[
                ['account_code' => 'CASH', 'debit_minor' => PHP_INT_MAX, 'credit_minor' => 0],
                ['account_code' => 'CASH', 'debit_minor' => 1, 'credit_minor' => 0],
                ['account_code' => 'REV', 'debit_minor' => 0, 'credit_minor' => PHP_INT_MAX],
            ]],
        ];
    }

    #[DataProvider('invalidLines')]
    public function test_invalid_journals_are_rejected_before_database_access(array $lines): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FinancialJournalPostingService())->post(1, 'key-1', 'folio', 'folio-1', 'Test posting', $lines);
    }

    public function test_empty_idempotency_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FinancialJournalPostingService())->post(1, '', 'folio', 'folio-1', 'Test posting', [
            ['account_code' => 'CASH', 'debit_minor' => 100, 'credit_minor' => 0],
            ['account_code' => 'REV', 'debit_minor' => 0, 'credit_minor' => 100],
        ]);
    }
}

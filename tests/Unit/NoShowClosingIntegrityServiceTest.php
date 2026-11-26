<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\NoShowClosingIntegrityService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class NoShowClosingIntegrityServiceTest extends TestCase
{
    public function test_clean_report_allows_close_guard(): void
    {
        (new NoShowClosingIntegrityService())->assertClear(['integrityValid' => true, 'issues' => []]);
        $this->addToAssertionCount(1);
    }

    public function test_invalid_report_rejects_close(): void
    {
        try {
            (new NoShowClosingIntegrityService())->assertClear([
                'integrityValid' => false,
                'issues' => [['reason' => 'duplicate_journal'], ['reason' => 'ledger_not_reconciled']],
            ]);
            self::fail('Invalid report must block closing.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('no_show_journal_integrity', $exception->errors());
            self::assertStringContainsString('2 no-show financial integrity issue(s)',
                $exception->errors()['no_show_journal_integrity'][0]);
        }
    }
}

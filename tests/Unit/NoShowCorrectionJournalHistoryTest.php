<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\NoShowCorrectionJournalHistory as History;
use PHPUnit\Framework\TestCase;

final class NoShowCorrectionJournalHistoryTest extends TestCase
{
    public function test_full_reversal_has_zero_combined_movement(): void
    {
        $original = [
            ['accountCode' => 'AR', 'debitMinor' => 10000, 'creditMinor' => 0],
            ['accountCode' => 'revenue', 'debitMinor' => 0, 'creditMinor' => 10000],
        ];
        $reversal = [
            ['accountCode' => 'AR', 'debitMinor' => 0, 'creditMinor' => 10000],
            ['accountCode' => 'revenue', 'debitMinor' => 10000, 'creditMinor' => 0],
        ];
        $result = History::accountMovements($original, $reversal);
        self::assertSame([0, 0], array_column($result, 'combinedNetMinor'));
        self::assertSame([10000, -10000], array_column($result, 'originalNetMinor'));
    }

    public function test_replacement_preserves_only_replacement_net_movement(): void
    {
        $original = [['accountCode' => 'AR', 'debitMinor' => 10000, 'creditMinor' => 0]];
        $corrections = [
            ['accountCode' => 'AR', 'debitMinor' => 0, 'creditMinor' => 10000],
            ['accountCode' => 'AR', 'debitMinor' => 4000, 'creditMinor' => 0],
        ];
        self::assertSame([['accountCode' => 'AR', 'originalNetMinor' => 10000,
            'correctionNetMinor' => -6000, 'combinedNetMinor' => 4000]],
            History::accountMovements($original, $corrections));
    }

    public function test_empty_history_has_no_movements(): void
    {
        self::assertSame([], History::accountMovements([], []));
    }
}

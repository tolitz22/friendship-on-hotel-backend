<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\FinancialReadinessGate;
use Tests\TestCase;

final class FinancialReadinessGateTest extends TestCase
{
    public function test_gate_fails_closed_and_distinguishes_readiness(): void
    {
        self::assertSame('integrity_blocked', FinancialReadinessGate::evaluate([])['status']);
        self::assertSame('readiness_unverified', FinancialReadinessGate::evaluate(['integrityValid' => true])['status']);
        $report = ['integrityValid' => true, 'dailyClosing' => ['canClose' => false, 'blockers' => ['Open shift']]];
        self::assertFalse(FinancialReadinessGate::evaluate($report)['financialGatePassed']);
        self::assertSame('operational_blocked', FinancialReadinessGate::evaluate($report)['status']);
        $report['dailyClosing'] = ['canClose' => true, 'blockers' => []];
        self::assertTrue(FinancialReadinessGate::evaluate($report)['financialGatePassed']);
        self::assertFalse(FinancialReadinessGate::evaluate($report)['releaseCertified']);
    }
}

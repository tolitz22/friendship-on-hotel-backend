<?php

declare(strict_types=1);

namespace App\Services\Finance;

final class FinancialReadinessGate
{
    public static function evaluate(array $report): array
    {
        $integrity = ($report['integrityValid'] ?? false) === true;
        $closing = $report['dailyClosing'] ?? null;
        $operational = is_array($closing) && ($closing['canClose'] ?? false) === true;
        $status = !$integrity ? 'integrity_blocked' : ($closing === null ? 'readiness_unverified'
            : ($operational ? 'financial_checks_passed' : 'operational_blocked'));

        return [
            'status' => $status,
            'financialGatePassed' => $integrity && $operational,
            'integrityValid' => $integrity,
            'integrityIssueCount' => count($report['issues'] ?? []),
            'closingReadinessChecked' => $closing !== null,
            'closingBlockers' => $closing['blockers'] ?? [],
            'notChecked' => $report['notChecked'] ?? [],
            'releaseCertified' => false,
            'readOnly' => true,
        ];
    }
}

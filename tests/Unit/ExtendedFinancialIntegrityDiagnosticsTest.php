<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\ExtendedFinancialIntegrityDiagnostics;
use Tests\TestCase;

final class ExtendedFinancialIntegrityDiagnosticsTest extends TestCase
{
    public function test_overallocation_is_detected_per_payment_not_only_in_total(): void
    {
        $statement = [
            'signed' => false, 'signedSnapshotIntegrity' => true,
            'payments' => [
                ['id' => '1', 'status' => 'staff_confirmed', 'verificationStatus' => 'verified', 'amountMinor' => 100],
                ['id' => '2', 'status' => 'staff_confirmed', 'verificationStatus' => 'verified', 'amountMinor' => 100],
            ],
            'allocations' => [['paymentId' => '1', 'amountMinor' => 110]],
            'refunds' => [],
        ];
        self::assertSame(['folio_payment_overallocated_or_unverified'], ExtendedFinancialIntegrityDiagnostics::folioFindings($statement));
        $statement['allocations'][0]['amountMinor'] = 90;
        self::assertSame([], ExtendedFinancialIntegrityDiagnostics::folioFindings($statement));
        $statement['refunds'][] = ['paymentId' => '1', 'status' => 'approved', 'evidenceStatus' => 'verified', 'amountMinor' => 20];
        self::assertSame(['folio_payment_overallocated_or_unverified'], ExtendedFinancialIntegrityDiagnostics::folioFindings($statement));
    }

    public function test_unsettled_unsigned_folio_is_not_automatically_corrupt(): void
    {
        $statement = ['signed' => false, 'signedSnapshotIntegrity' => true, 'payments' => [], 'allocations' => [], 'refunds' => []];
        self::assertSame([], ExtendedFinancialIntegrityDiagnostics::folioFindings($statement));
        $statement['signed'] = true;
        $statement['signedSnapshotIntegrity'] = false;
        self::assertSame(['signed_folio_snapshot_mismatch'], ExtendedFinancialIntegrityDiagnostics::folioFindings($statement));
    }
}

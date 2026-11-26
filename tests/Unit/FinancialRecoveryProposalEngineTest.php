<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\FinancialRecoveryProposalEngine;
use Tests\TestCase;

final class FinancialRecoveryProposalEngineTest extends TestCase
{
    public function test_fingerprint_is_stable_for_reordered_fields_and_sensitive_to_scope(): void
    {
        $first = ['hotelId' => '2', 'detail' => ['reason' => 'payment_evidence_unresolved', 'reference' => 'folio-payment-4']];
        $second = ['detail' => ['reference' => 'folio-payment-4', 'reason' => 'payment_evidence_unresolved'], 'hotelId' => '2'];
        self::assertSame(FinancialRecoveryProposalEngine::fingerprint($first), FinancialRecoveryProposalEngine::fingerprint($second));
        $second['hotelId'] = '3';
        self::assertNotSame(FinancialRecoveryProposalEngine::fingerprint($first), FinancialRecoveryProposalEngine::fingerprint($second));
    }

    public function test_rejected_payment_recommendation_preserves_independent_review(): void
    {
        self::assertStringContainsString('Never mark rejected evidence verified', FinancialRecoveryProposalEngine::action('payment_evidence_unresolved'));
        self::assertStringContainsString('Never reopen', FinancialRecoveryProposalEngine::action('business_date_already_closed'));
    }
}

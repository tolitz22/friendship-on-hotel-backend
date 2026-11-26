<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\NoShowJournalIntegrityService as Integrity;
use PHPUnit\Framework\TestCase;

final class NoShowJournalIntegrityServiceTest extends TestCase
{
    public function test_source_references_require_canonical_positive_ids(): void
    {
        self::assertSame('42', Integrity::sourceId('folio-payment-', 'folio-payment-42'));
        foreach (['folio-payment-0', 'folio-payment-042', 'folio-payment--1',
            'folio-payment-42x', 'proposal-42', 'folio-payment-9223372036854775808'] as $reference) {
            self::assertNull(Integrity::sourceId('folio-payment-', $reference));
        }
    }

    public function test_receipt_and_opening_recognition_share_duplicate_identity(): void
    {
        self::assertSame(
            Integrity::duplicateKey('guest_deposit_receipt', 'folio-payment-42', null),
            Integrity::duplicateKey('guest_deposit_opening_reclassification', 'folio-payment-42', null)
        );
    }

    public function test_room_correction_allows_distinct_originals_but_not_duplicate_reversal(): void
    {
        $first = Integrity::duplicateKey('no_show_room_correction', 'proposal-3', 10);
        self::assertSame($first, Integrity::duplicateKey('no_show_room_correction', 'proposal-3', '10'));
        self::assertNotSame($first, Integrity::duplicateKey('no_show_room_correction', 'proposal-3', 11));
    }

    public function test_fee_reversal_and_replacement_are_distinct(): void
    {
        self::assertNotSame(
            Integrity::duplicateKey('no_show_fee_reversal', 'proposal-3', 10),
            Integrity::duplicateKey('no_show_fee_replacement', 'proposal-3', null)
        );
    }
}

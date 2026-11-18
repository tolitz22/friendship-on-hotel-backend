<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\FolioNightTaxAllocation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FolioNightTaxAllocationTest extends TestCase
{
    public function test_standard_vat_inclusive_room_night_preserves_guest_price(): void
    {
        $result = (new FolioNightTaxAllocation())->calculate(11200, 'vat_standard', 'inclusive', 1200);
        self::assertSame(11200, $result['gross_minor']);
        self::assertSame(10000, $result['room_revenue_minor']);
        self::assertSame(1200, $result['vat_minor']);
        self::assertSame(0, $result['folio_credit_minor']);
        self::assertSame(11200, $result['payable_minor']);
    }

    public function test_eligible_senior_night_has_vat_relief_and_twenty_percent_discount(): void
    {
        $result = (new FolioNightTaxAllocation())->calculate(
            11200, 'vat_standard', 'inclusive', 1200, 'senior', 11200
        );
        self::assertSame(2000, $result['statutory_discount_minor']);
        self::assertSame(3200, $result['folio_credit_minor']);
        self::assertSame(0, $result['vat_minor']);
        self::assertSame(8000, $result['room_revenue_minor']);
        self::assertSame(8000, $result['payable_minor']);
    }

    public function test_mixed_eligibility_does_not_discount_other_guests(): void
    {
        $result = (new FolioNightTaxAllocation())->calculate(
            22400, 'vat_standard', 'inclusive', 1200, 'pwd', 11200
        );
        self::assertSame(1200, $result['vat_minor']);
        self::assertSame(3200, $result['folio_credit_minor']);
        self::assertSame(18000, $result['room_revenue_minor']);
        self::assertSame(19200, $result['payable_minor']);
    }

    public function test_exclusive_rates_do_not_silently_add_guest_charges(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FolioNightTaxAllocation())->calculate(10000, 'vat_standard', 'exclusive', 1200);
    }

    public function test_stacking_or_arbitrary_eligible_amount_is_rejected(): void
    {
        foreach ([
            [11200, 'vat_standard', 'inclusive', 1200, 'none', 11200],
            [11200, 'vat_standard', 'inclusive', 1200, 'senior', 0],
            [11200, 'vat_standard', 'inclusive', 1200, 'pwd', 12000],
            [11200, 'vat_exempt', 'inclusive', 0, 'senior', 11200],
        ] as $arguments) {
            try {
                (new FolioNightTaxAllocation())->calculate(...$arguments);
                self::fail('Invalid benefit allocation must fail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}

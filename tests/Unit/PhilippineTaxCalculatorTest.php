<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\PhilippineTaxCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PhilippineTaxCalculatorTest extends TestCase
{
    public function test_vat_inclusive_uses_integer_centavos(): void
    {
        $result = (new PhilippineTaxCalculator())->calculate(11200, 'vat_standard', 'inclusive', 1200);
        self::assertSame(10000, $result['base_minor']);
        self::assertSame(1200, $result['tax_minor']);
        self::assertSame(11200, $result['total_minor']);
        self::assertSame(11200, $result['base_minor'] + $result['tax_minor']);
    }

    public function test_vat_exclusive_uses_integer_centavos(): void
    {
        $result = (new PhilippineTaxCalculator())->calculate(10000, 'vat_standard', 'exclusive', 1200);
        self::assertSame(10000, $result['base_minor']);
        self::assertSame(1200, $result['tax_minor']);
        self::assertSame(11200, $result['total_minor']);
    }

    public function test_inclusive_rounds_small_amounts_without_floating_point(): void
    {
        $result = (new PhilippineTaxCalculator())->calculate(100, 'vat_standard', 'inclusive', 1200);
        self::assertSame(89, $result['base_minor']);
        self::assertSame(11, $result['tax_minor']);
        self::assertSame(100, $result['total_minor']);
    }

    public function test_zero_rated_exempt_and_nonvat_are_distinct(): void
    {
        foreach (['vat_zero', 'vat_exempt', 'non_vat'] as $classification) {
            $result = (new PhilippineTaxCalculator())->calculate(10000, $classification, 'inclusive', 0);
            self::assertSame($classification, $result['classification']);
            self::assertSame(0, $result['tax_minor']);
            self::assertSame(10000, $result['total_minor']);
        }
    }

    public function test_nonstandard_rates_can_be_configured(): void
    {
        $result = (new PhilippineTaxCalculator())->calculate(10500, 'vat_standard', 'inclusive', 500);
        self::assertSame(10000, $result['base_minor']);
        self::assertSame(500, $result['tax_minor']);
    }

    public function test_invalid_classification_or_rate_is_rejected(): void
    {
        foreach ([
            [-1, 'vat_standard', 'inclusive', 1200],
            [100, 'vat_exempt', 'exclusive', 1200],
            [100, 'vat_standard', 'exclusive', 0],
            [100, 'vat_standard', 'exclusive', 10001],
            [100, 'unknown', 'inclusive', 0],
            [100, 'vat_standard', 'other', 1200],
            [1000000000001, 'vat_standard', 'inclusive', 1200],
        ] as $input) {
            try {
                (new PhilippineTaxCalculator())->calculate(...$input);
                self::fail('Invalid tax configuration must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}

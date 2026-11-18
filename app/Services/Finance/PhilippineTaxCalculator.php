<?php

declare(strict_types=1);

namespace App\Services\Finance;

use InvalidArgumentException;

/**
 * Integer-only, auditable breakdown. Rate is basis points (1200 = 12%).
 * This class does not determine eligibility for statutory exemptions or
 * discounts, issue invoices, create folio charges, or post journals.
 */
final class PhilippineTaxCalculator
{
    /**
     * @return array{classification:string,price_mode:string,rate_bps:int,base_minor:int,tax_minor:int,total_minor:int}
     */
    public function calculate(
        int $amountMinor,
        string $classification,
        string $priceMode,
        int $rateBps
    ): array {
        if ($amountMinor < 0 || $amountMinor > 1000000000000) {
            throw new InvalidArgumentException('Amount must be nonnegative centavos within accounting limits.');
        }
        if (!in_array($classification, ['vat_standard', 'vat_zero', 'vat_exempt', 'non_vat'], true)) {
            throw new InvalidArgumentException('Unsupported tax classification.');
        }
        if (!in_array($priceMode, ['inclusive', 'exclusive'], true)) {
            throw new InvalidArgumentException('Unsupported price mode.');
        }
        if ($rateBps < 0 || $rateBps > 10000
            || ($classification !== 'vat_standard' && $rateBps !== 0)
            || ($classification === 'vat_standard' && $rateBps === 0)) {
            throw new InvalidArgumentException('Rate does not match the configured tax classification.');
        }

        if ($priceMode === 'inclusive') {
            // Half-up to the nearest centavo; integers avoid floating-point drift.
            $base = intdiv($amountMinor * 10000 + intdiv(10000 + $rateBps, 2), 10000 + $rateBps);
            $tax = $amountMinor - $base;
            $total = $amountMinor;
        } else {
            $base = $amountMinor;
            $tax = intdiv($base * $rateBps + 5000, 10000);
            $total = $base + $tax;
        }

        return [
            'classification' => $classification,
            'price_mode' => $priceMode,
            'rate_bps' => $rateBps,
            'base_minor' => $base,
            'tax_minor' => $tax,
            'total_minor' => $total,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Finance;

use InvalidArgumentException;

/**
 * Room-night-only tax allocation against the EXISTING folio entry amount.
 * Ineligible to issue an invoice; discount eligibility must be verified by staff.
 * The existing folio total is already VAT-inclusive: exclusive policies are
 * rejected rather than creating an undisclosed extra charge.
 */
final class FolioNightTaxAllocation
{
    public function calculate(
        int $grossMinor,
        string $classification,
        string $priceMode,
        int $rateBps,
        string $benefitKind = 'none',
        int $eligibleGrossMinor = 0
    ): array {
        if ($grossMinor < 1 || $grossMinor > 1000000000000 || $priceMode !== 'inclusive'
            || !in_array($benefitKind, ['none', 'senior', 'pwd'], true)
            || $eligibleGrossMinor < 0 || $eligibleGrossMinor > $grossMinor
            || ($benefitKind === 'none' && $eligibleGrossMinor !== 0)
            || ($benefitKind !== 'none' && $eligibleGrossMinor === 0)
            || ($benefitKind !== 'none' && $classification !== 'vat_standard')) {
            throw new InvalidArgumentException('Unsupported room-night amount, pricing, or benefit allocation.');
        }
        $tax = new PhilippineTaxCalculator();
        $regularGross = $grossMinor - $eligibleGrossMinor;
        $regular = $tax->calculate($regularGross, $classification, $priceMode, $rateBps);
        $discount = 0;
        $eligibleBase = 0;
        if ($benefitKind !== 'none') {
            $eligible = $tax->calculate($eligibleGrossMinor, 'vat_standard', 'inclusive', $rateBps);
            $eligibleBase = $eligible['base_minor'];
            // 20% of the qualified VAT-exclusive portion, rounded half up.
            $discount = intdiv($eligibleBase * 20 + 50, 100);
        }

        $payable = $regularGross + $eligibleBase - $discount;
        $credit = $grossMinor - $payable;
        $revenue = $regular['base_minor'] + $eligibleBase - $discount;
        $vat = $regular['tax_minor'];

        if ($credit < 0 || $revenue < 0 || $payable < 0
            || $credit + $vat + $revenue !== $grossMinor
            || $revenue + $vat !== $payable) {
            throw new InvalidArgumentException('Room-night tax allocation does not reconcile.');
        }

        return [
            'gross_minor' => $grossMinor,
            'eligible_gross_minor' => $eligibleGrossMinor,
            'benefit_kind' => $benefitKind,
            'statutory_discount_minor' => $discount,
            'folio_credit_minor' => $credit,
            'vat_minor' => $vat,
            'room_revenue_minor' => $revenue,
            'payable_minor' => $payable,
            'classification' => $classification,
            'rate_bps' => $rateBps,
        ];
    }
}

<?php

declare(strict_types=1);

return [
    // Keep new expense writes disabled until staging acceptance and finance sign-off.
    'enable_expense_posting' => (bool) env('FINANCE_ENABLE_EXPENSE_POSTING', false),
    /*
     * OFF by default. Requires staging validation, tax registration review,
     * historical folio assessment/backfill and formal finance sign-off.
     * Setting this true enables NEW folio tax/discount journal postings and
     * enforces completed folio-night accounting during day close.
     * Never enable on production merely to make tests pass.
     */
    'enable_live_folio_tax' => (bool) env('FINANCE_ENABLE_LIVE_FOLIO_TAX', false),
    /*
     * Phase 61 settlement signing and checkout lock are opt-in until
     * historical payments and folios have been reviewed in staging.
     * Existing signed folios remain immutable even after rollback.
     */
    'enable_verified_settlement' => (bool) env('FINANCE_ENABLE_VERIFIED_SETTLEMENT', false),
    /*
     * Optional property-wide simplified CASH checkout.
     * Only newly recorded, shift-linked, staff-confirmed cash qualifies.
     * Cashier shift remains subject to independent review after departure.
     * DO NOT activate for live guests without validated finance procedures.
     */
    'simplified_cashier_mode' => (bool) env('FINANCE_SIMPLIFIED_CASHIER_MODE', false),
];

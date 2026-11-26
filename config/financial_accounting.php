<?php

declare(strict_types=1);

return [
    // Keep fee reversal posting off until atomic folio and replacement integration passes acceptance.
    'enable_no_show_fee_correction_posting' => (bool) env('FINANCE_ENABLE_NO_SHOW_FEE_CORRECTION_POSTING', false),
    // Fee correction review is staged; approval does not post any journals.
    'enable_no_show_fee_correction_review' => (bool) env('FINANCE_ENABLE_NO_SHOW_FEE_CORRECTION_REVIEW', false),
    // Atomic room-night reversals require independent approval and separate acceptance.
    'enable_no_show_room_correction_posting' => (bool) env('FINANCE_ENABLE_NO_SHOW_ROOM_CORRECTION_POSTING', false),
    // Review only; no room-night reversal posting is activated by this flag.
    'enable_no_show_room_correction_review' => (bool) env('FINANCE_ENABLE_NO_SHOW_ROOM_CORRECTION_REVIEW', false),
    // External no-show refund journals require separate financial acceptance.
    'enable_no_show_refund_posting' => (bool) env('FINANCE_ENABLE_NO_SHOW_REFUND_POSTING', false),
    // No-show deposit allocation journals require staged end-to-end acceptance.
    'enable_no_show_allocation_posting' => (bool) env('FINANCE_ENABLE_NO_SHOW_ALLOCATION_POSTING', false),
    // Cash deposit posting requires independent cashier reconciliation and separate acceptance.
    'enable_cash_guest_deposit_posting' => (bool) env('FINANCE_ENABLE_CASH_GUEST_DEPOSIT_POSTING', false),
    // Historical clearing reclassification requires a reconciled opening import and separate acceptance.
    'enable_historical_guest_deposit_posting' => (bool) env('FINANCE_ENABLE_HISTORICAL_GUEST_DEPOSIT_POSTING', false),
    // Staged no-show bank deposit journals; keep off until reconciliation and acceptance.
    'enable_guest_deposit_posting' => (bool) env('FINANCE_ENABLE_GUEST_DEPOSIT_POSTING', false),
    // Enable only after reimbursement acceptance and finance sign-off.
    'enable_employee_reimbursements' => (bool) env('FINANCE_ENABLE_EMPLOYEE_REIMBURSEMENTS', false),
    // Supplier financial posting requires separate acceptance and finance sign-off.
    'enable_supplier_billing' => (bool) env('FINANCE_ENABLE_SUPPLIER_BILLING', false),
    // Keep new expense writes disabled until staging acceptance and finance sign-off.
    'enable_expense_posting' => (bool) env('FINANCE_ENABLE_EXPENSE_POSTING', false),
    // Separate rollout control; never enable until correction and refund acceptance passes.
    'enable_expense_corrections' => (bool) env('FINANCE_ENABLE_EXPENSE_CORRECTIONS', false),
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

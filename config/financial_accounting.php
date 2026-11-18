<?php

declare(strict_types=1);

return [
    /*
     * OFF by default. Requires staging validation, tax registration review,
     * historical folio assessment/backfill and formal finance sign-off.
     * Setting this true enables NEW folio tax/discount journal postings and
     * enforces completed folio-night accounting during day close.
     * Never enable on production merely to make tests pass.
     */
    'enable_live_folio_tax' => (bool) env('FINANCE_ENABLE_LIVE_FOLIO_TAX', false),
];

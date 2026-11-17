<?php

declare(strict_types=1);

return [
    // No live payment provider is configured by default. This deliberately
    // generic HMAC contract is NOT interchangeable with any provider's webhook.
    'custom_hmac_v1' => [
        'enabled' => (bool) env('FINANCE_CUSTOM_HMAC_ENABLED', false),
        'secret' => env('FINANCE_CUSTOM_HMAC_SECRET'),
        'max_clock_skew_seconds' => 300,
    ],
];

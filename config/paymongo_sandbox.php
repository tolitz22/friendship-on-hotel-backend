<?php

declare(strict_types=1);

return [
    // Only the PayMongo test-mode webhook contract is supported in Phase 28.
    // Never supply a live-mode signing key here.
    'enabled' => (bool) env('PAYMONGO_SANDBOX_WEBHOOK_ENABLED', false),
    'webhook_secret' => env('PAYMONGO_SANDBOX_WEBHOOK_SECRET'),
    // Bind a single, independently verified sandbox merchant endpoint to one
    // hotel. Do not trust a user-supplied hotel ID in a webhook payload.
    'hotel_id' => env('PAYMONGO_SANDBOX_HOTEL_ID'),
    'max_skew_seconds' => 300,
];

<?php

declare(strict_types=1);

return [
    'dynamic_pricing_enabled' => env('BOOKING_DYNAMIC_PRICING_ENABLED', false),
    // Conservative initial operating defaults, replace with versioned hotel-level
    // settings when the client has a different reservation policy.
    'inventory_unit' => 'room_type',
    'assignment_timing' => 'before_arrival',
    'consuming_states' => ['confirmed', 'checked_in'],
    'released_states' => ['cancelled', 'checked_out', 'no_show'],
    // Legacy/unclassified records block capacity until reviewed.
    'unclassified_records_block_inventory' => true,
    'overbooking' => false,
    'minimum_nights' => 1,
    'maximum_nights' => 31,
    'cancellation' => 'admin_only_confirmed',
    // No payment, rate quote or temporary hold is implied by confirmation.
    'payments_enabled' => false,
    'temporary_holds_enabled' => false,
];

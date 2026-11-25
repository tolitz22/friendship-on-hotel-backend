<?php

declare(strict_types=1);

return [
    'dynamic_pricing_enabled' => env('BOOKING_DYNAMIC_PRICING_ENABLED', false),
    // Enable only after priced amendment accounting regression and acceptance.
    'priced_amendments_enabled' => env('BOOKING_PRICED_AMENDMENTS_ENABLED', false),
    // Keep no-show financial writes disabled until accounting acceptance.
    'no_show_adjustments_enabled' => env('BOOKING_NO_SHOW_ADJUSTMENTS_ENABLED', false),
    'no_show_adjustments_hotel_ids' => env('BOOKING_NO_SHOW_ADJUSTMENTS_HOTEL_IDS', ''),
    'no_show_cutoff_next_day' => env('BOOKING_NO_SHOW_CUTOFF_NEXT_DAY', '06:00'),
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

<?php

return [
    'enabled' => env('PMS_PUSH_ENABLED', false),
    'public_key' => env('PMS_PUSH_PUBLIC_KEY'),
    'private_key' => env('PMS_PUSH_PRIVATE_KEY'),
    'subject' => env('PMS_PUSH_SUBJECT'),
    'scope' => env('PMS_PUSH_SCOPE', 'pilot'),
    'hotel_id' => env('PMS_PUSH_HOTEL_ID'),
    'allow_non_demo' => env('PMS_PUSH_ALLOW_NON_DEMO', false),
];

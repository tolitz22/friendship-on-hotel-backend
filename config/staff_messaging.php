<?php

return [
    'realtime' => [
        'enabled' => env('STAFF_REALTIME_ENABLED', false),
        'app_id' => env('STAFF_REALTIME_APP_ID'),
        'key' => env('STAFF_REALTIME_KEY'),
        'secret' => env('STAFF_REALTIME_SECRET'),
        'http_url' => env('STAFF_REALTIME_HTTP_URL', 'https://api-mt1.pusher.com'),
    ],
];

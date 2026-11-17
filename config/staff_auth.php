<?php

declare(strict_types=1);

return [
    // Must be the trusted frontend password reset page, never a user-supplied URL.
    'reset_url' => env('STAFF_PASSWORD_RESET_URL', 'http://localhost:5173/reset-password'),
];

<?php

declare(strict_types=1);

return [
    // Opt in only when the SPA and API share a trusted same-site domain.
    'enabled' => (bool) env('SPA_SESSION_ENABLED', false),
];

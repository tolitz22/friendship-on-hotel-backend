<?php

declare(strict_types=1);

namespace App\Logging;

final class MonitoringRoutes
{
    public const ROUTES = [
        'api/v1/platform/monitoring', 'api/v1/platform/monitoring/metrics',
        'api/v1/platform/health', 'api/v1/platform/workers',
    ];

    public static function contains(mixed $route): bool
    {
        return in_array($route, self::ROUTES, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

final class PushDeliveryOutcome
{
    public static function classify(?int $status, bool $success = false): array
    {
        if ($success) {
            return ['sent', null];
        }

        return match (true) {
            in_array($status, [404, 410], true) => ['expired', 'endpoint_expired'],
            $status === 429 => ['pending', 'provider_rate_limited'],
            $status === null, $status === 408, $status >= 500 => ['pending', 'provider_temporary'],
            in_array($status, [401, 403], true) => ['failed', 'provider_authorization'],
            default => ['failed', 'provider_rejected'],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Logging;

final class ServiceRole
{
    // An explicit, bounded deployment identity. Never infer from hostnames,
    // request input, command arguments or arbitrary Railway service names.
    public static function normalize(mixed $value): string
    {
        return in_array($value, ['backend', 'worker', 'scheduler'], true) ? $value : 'unspecified';
    }
}

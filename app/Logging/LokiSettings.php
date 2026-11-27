<?php

declare(strict_types=1);

namespace App\Logging;

final class LokiSettings
{
    public static function valid(string $url, string $username, string $token): bool
    {
        if ($username === '' || $token === '' || strlen($username) > 128 || strlen($token) > 4096
            || preg_match('/[\\x00-\\x20\\x7f]/', $username.$token) === 1 || str_contains($username, ':')) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host']) && ($parts['path'] ?? null) === '/loki/api/v1/push'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']);
    }
}

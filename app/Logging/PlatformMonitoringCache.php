<?php

declare(strict_types=1);

namespace App\Logging;

use Closure;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class PlatformMonitoringCache
{
    private const TTL = 30;
    private const SLOTS = 16;

    public function read(string $kind, array $filters, Closure $load): array
    {
        $connection = PlatformMonitoringConnection::settings();
        if ($connection === null || ! in_array($kind, ['events', 'metrics'], true)) {
            return $load();
        }

        // The fixed slots prevent unbounded accumulation of expired file-cache
        // keys from arbitrary request UUIDs. The full digest verifies identity.
        $digest = hash('sha256', json_encode([$kind, $filters, $connection], JSON_THROW_ON_ERROR));
        $key = 'pms:platform-monitoring:v1:'.$kind.':'.(hexdec(substr($digest, 0, 4)) % self::SLOTS);
        try {
            $store = Cache::store();
            $hit = $this->hit($store->get($key), $digest);
            if ($hit !== null) {
                return $hit;
            }
            $lock = $store->lock($key.':lock', 20);
            $acquired = $lock->get();
        } catch (Throwable) {
            // A cache outage must not block an authorized monitoring read.
            return $load();
        }
        if (! $acquired) {
            throw new HttpException(429, 'A monitoring query is already running. Try again shortly.', null, ['Retry-After' => '3']);
        }

        try {
            try {
                $hit = $this->hit($store->get($key), $digest);
                if ($hit !== null) {
                    return $hit;
                }
            } catch (Throwable) {
                // Continue with a fresh read while holding the lock.
            }
            $result = $load();
            if (($result['state'] ?? null) === 'ready') {
                try {
                    $store->put($key, ['digest' => $digest, 'expires' => time() + self::TTL, 'data' => $result], self::TTL);
                } catch (Throwable) {
                    // Return the fresh result even when caching fails.
                }
            }

            return $result;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Lease expires automatically.
            }
        }
    }

    private function hit(mixed $entry, string $digest): ?array
    {
        return is_array($entry) && ($entry['digest'] ?? null) === $digest
            && ($entry['expires'] ?? 0) > time() && is_array($entry['data'] ?? null)
            ? $entry['data'] : null;
    }
}

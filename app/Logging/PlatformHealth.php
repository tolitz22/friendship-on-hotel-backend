<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class PlatformHealth
{
    public function snapshot(): array
    {
        $database = $this->database();
        $cache = $this->cache();
        $grafana = $this->grafana();
        $environment = config('app.env');
        $revision = config('platform.deployment_revision');
        $services = [$database, $cache, $grafana];
        $overall = count(array_filter($services, static fn (array $s): bool => $s['state'] !== 'healthy')) === 0 ? 'healthy' : 'degraded';

        return [
            'observedAt' => now()->toIso8601String(),
            'state' => $overall,
            'backend' => ['state' => 'healthy', 'apiVersion' => 'v1'],
            'database' => $database,
            'cache' => $cache,
            'grafana' => $grafana,
            'deployment' => [
                'environment' => in_array($environment, ['local', 'testing', 'staging', 'production'], true) ? $environment : 'other',
                'serviceRole' => ServiceRole::normalize(config('logging.service_role')),
                'revision' => is_string($revision) && preg_match('/\A[0-9a-f]{7,40}\z/D', $revision) ? $revision : null,
                'debug' => (bool) config('app.debug'),
            ],
        ];
    }

    private function database(): array
    {
        $started = hrtime(true);
        $driver = 'unknown';
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $driver = in_array($driver, ['pgsql', 'mysql', 'sqlite'], true) ? $driver : 'other';
            $connection->selectOne('SELECT 1 AS health_check');
            $duration = self::elapsed($started);
        } catch (Throwable) {
            return ['state' => 'unavailable', 'driver' => $driver, 'latencyMs' => self::elapsed($started), 'sizeBytes' => null, 'sizeState' => 'unavailable'];
        }

        $size = null;
        $sizeState = 'unsupported';
        if ($driver === 'pgsql') {
            try {
                $row = $connection->selectOne('SELECT pg_database_size(current_database()) AS bytes');
                $size = is_numeric($row->bytes ?? null) && (float) $row->bytes >= 0 && (float) $row->bytes <= 9007199254740991 ? (int) $row->bytes : null;
                $sizeState = $size === null ? 'unavailable' : 'ready';
            } catch (Throwable) {
                $sizeState = 'unavailable';
            }
        }

        return ['state' => $duration > 500 ? 'degraded' : 'healthy', 'driver' => $driver, 'latencyMs' => $duration, 'sizeBytes' => $size, 'sizeState' => $sizeState];
    }

    private function cache(): array
    {
        $started = hrtime(true);
        $driver = config('cache.stores.'.config('cache.default').'.driver');
        $driver = in_array($driver, ['array', 'file', 'database', 'redis'], true) ? $driver : 'other';
        $key = 'pms:health:'.Str::uuid();
        $value = (string) Str::uuid();
        $state = 'unavailable';
        try {
            $store = Cache::store();
            if ($store->put($key, $value, 30) && $store->get($key) === $value) {
                $state = 'healthy';
            }
        } catch (Throwable) {
            // No exception details or actual cache keys returned.
        } finally {
            if (isset($store)) {
                try {
                    $store->forget($key);
                } catch (Throwable) {
                    $state = 'degraded';
                }
            }
        }
        $duration = self::elapsed($started);
        if ($state === 'healthy' && ($duration > 500 || $driver === 'array')) {
            $state = 'degraded';
        }

        return ['state' => $state, 'driver' => $driver, 'latencyMs' => $duration, 'scope' => in_array($driver, ['file', 'array'], true) ? 'local' : 'configured'];
    }

    private function grafana(): array
    {
        $connection = PlatformMonitoringConnection::settings();
        if ($connection === null) {
            return ['state' => 'not_configured', 'latencyMs' => null];
        }
        $started = hrtime(true);
        try {
            $end = time();
            $response = Http::withToken($connection['token'])->acceptJson()->withOptions(['allow_redirects' => false])
                ->connectTimeout(2)->timeout(5)->get($connection['endpoint'].'query_range', [
                    'query' => '{'.$connection['label'].'="friendship-on-hotel-backend"}',
                    'start' => (string) ($end - 60).'000000000',
                    'end' => (string) $end.'000000000', 'limit' => 1, 'direction' => 'backward',
                ]);
            $duration = self::elapsed($started);
            $valid = $response->successful() && strlen($response->body()) <= 262144 && $response->json('status') === 'success'
                && $response->json('data.resultType') === 'streams' && is_array($response->json('data.result'));

            return ['state' => $valid ? ($duration > 2000 ? 'degraded' : 'healthy') : 'unavailable', 'latencyMs' => $duration];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'latencyMs' => self::elapsed($started)];
        }
    }

    private static function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1000000);
    }
}

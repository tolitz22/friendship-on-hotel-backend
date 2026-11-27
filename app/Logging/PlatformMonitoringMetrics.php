<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PlatformMonitoringMetrics
{
    private const ERRORS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    private const COUNTS = ['requests', 'serverErrors', 'errors', 'frontendErrors', 'authFailures'];

    public function read(int $minutes, string $environment, string $role, ?string $requestId): array
    {
        $end = time();
        $step = intdiv($minutes * 60, 12);
        $result = ['state' => 'not_configured', 'observedAt' => gmdate(DATE_ATOM, $end), 'minutes' => $minutes,
            'counts' => null, 'p95' => null, 'latencyState' => 'unavailable', 'trend' => null];
        $connection = PlatformMonitoringConnection::settings();
        if ($connection === null) {
            return $result;
        }

        // Extract only the needed fields; never turn request UUIDs or arbitrary
        // context into aggregation dimensions. All filter values are validated
        // by the controller, and no arbitrary LogQL is accepted.
        $selector = '{'.$connection['label'].'="friendship-on-hotel-backend"}';
        $fields = 'pms_event="message", pms_level="level_name", pms_status="context.status_code", pms_schema="extra.schema_version", pms_service="extra.service", pms_env="extra.environment", pms_role="extra.service_role"';
        if ($requestId !== null) {
            $fields .= ', pms_request="context.request_id", pms_related="context.related_request_id"';
        }
        $filter = ' | __error__="" | pms_schema="1" | pms_service="friendship-on-hotel-backend"';
        if ($environment !== 'all') {
            $filter .= ' | pms_env="'.$environment.'"';
        }
        if ($role !== 'all') {
            $filter .= ' | pms_role="'.$role.'"';
        }
        if ($requestId !== null) {
            $filter .= ' | pms_request="'.$requestId.'" or pms_related="'.$requestId.'"';
        }
        $pipeline = $selector.' | json '.$fields.$filter;
        $latency = $selector.' | json '.$fields.', pms_duration="context.duration_ms"'.$filter
            .' | pms_event="pms.api.request.completed" | pms_duration!="" | unwrap pms_duration | __error__=""';
        $range = '['.$minutes.'m]';
        $queries = [
            'counts' => ['query', ['query' => 'sum by (pms_event,pms_level,pms_status) (count_over_time('.$pipeline.$range.'))', 'time' => $end]],
            'latency' => ['query', ['query' => 'quantile_over_time(0.95, '.$latency.$range.') by (pms_service)', 'time' => $end]],
            'trend' => ['query_range', ['query' => 'sum by (pms_level) (count_over_time('.$pipeline.' | pms_level=~"WARNING|ERROR|CRITICAL|ALERT|EMERGENCY"['.$step.'s]))',
                'start' => (string) ($end - 11 * $step).'000000000', 'end' => (string) $end.'000000000', 'step' => $step]],
        ];
        $result['state'] = 'unavailable';
        try {
            $responses = Http::pool(function (Pool $pool) use ($connection, $queries): array {
                $requests = [];
                foreach ($queries as $name => [$path, $params]) {
                    $requests[] = $pool->as($name)->withToken($connection['token'])->acceptJson()
                        ->withOptions(['allow_redirects' => false])->connectTimeout(2)->timeout(8)
                        ->get($connection['endpoint'].$path, $params);
                }

                return $requests;
            });
            $counts = self::vector($responses['counts'] ?? null);
            if ($counts !== null) {
                $totals = array_fill_keys(self::COUNTS, 0);
                foreach ($counts as $series) {
                    $n = self::countValue($series['value'][1] ?? null);
                    if ($n === null || ! is_array($series['metric'] ?? null)) {
                        $totals = null;
                        break;
                    }
                    $metric = $series['metric'];
                    $event = $metric['pms_event'] ?? '';
                    if ($event === 'pms.api.request.completed') {
                        $totals['requests'] += $n;
                        if (preg_match('/\A5[0-9]{2}\z/D', (string) ($metric['pms_status'] ?? ''))) {
                            $totals['serverErrors'] += $n;
                        }
                    }
                    if (in_array($metric['pms_level'] ?? '', self::ERRORS, true)) {
                        $totals['errors'] += $n;
                    }
                    if ($event === FrontendErrorLog::EVENT) {
                        $totals['frontendErrors'] += $n;
                    }
                    if ($event === 'pms.security.login.failed') {
                        $totals['authFailures'] += $n;
                    }
                }
                $result['counts'] = $totals;
            }
            $values = self::vector($responses['latency'] ?? null);
            if ($values === []) {
                $result['latencyState'] = 'no_data';
            } elseif (is_array($values) && count($values) === 1) {
                $value = $values[0]['value'][1] ?? null;
                if (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 86400000) {
                    $result['p95'] = round((float) $value, 1);
                    $result['latencyState'] = 'ready';
                }
            }
            $series = self::series($responses['trend'] ?? null, 'matrix');
            if ($series !== null) {
                $buckets = [];
                for ($i = 0; $i < 12; $i++) {
                    $t = $end - (11 - $i) * $step;
                    $buckets[$t] = ['start' => ($t - $step) * 1000, 'end' => $t * 1000, 'errors' => 0, 'warnings' => 0];
                }
                foreach ($series as $line) {
                    $level = $line['metric']['pms_level'] ?? null;
                    if (! in_array($level, [...self::ERRORS, 'WARNING'], true) || ! is_array($line['values'] ?? null)) {
                        $buckets = null;
                        break;
                    }
                    foreach ($line['values'] as $point) {
                        $t = $point[0] ?? null;
                        $n = self::countValue($point[1] ?? null);
                        if ((! is_int($t) && ! is_float($t)) || (int) $t != $t || ! isset($buckets[(int) $t]) || $n === null) {
                            $buckets = null;
                            break 2;
                        }
                        $buckets[(int) $t][$level === 'WARNING' ? 'warnings' : 'errors'] += $n;
                    }
                }
                $result['trend'] = $buckets === null ? null : array_values($buckets);
            }
            $available = ($result['counts'] !== null ? 1 : 0) + ($result['latencyState'] !== 'unavailable' ? 1 : 0) + ($result['trend'] !== null ? 1 : 0);
            $result['state'] = $available === 3 ? 'ready' : ($available > 0 ? 'partial' : 'unavailable');
        } catch (Throwable) {
            // Do not leak credentials, provider responses or exception text.
        }

        return $result;
    }

    private static function vector(mixed $response): ?array
    {
        return self::series($response, 'vector');
    }

    private static function series(mixed $response, string $type): ?array
    {
        if (! $response instanceof Response || ! $response->successful() || strlen($response->body()) > 1024 * 1024
            || $response->json('status') !== 'success' || $response->json('data.resultType') !== $type) {
            return null;
        }
        $series = $response->json('data.result');

        return is_array($series) && array_is_list($series) && count($series) <= 500 && count(array_filter($series, 'is_array')) === count($series) ? $series : null;
    }

    private static function countValue(mixed $value): ?int
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 1000000000000 || floor((float) $value) !== (float) $value) {
            return null;
        }

        return (int) $value;
    }
}

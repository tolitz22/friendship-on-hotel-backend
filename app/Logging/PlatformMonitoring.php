<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Http;
use Throwable;

final class PlatformMonitoring
{
    private const LIMIT = 500;
    private const SERVICE = 'friendship-on-hotel-backend';

    public function snapshot(int $minutes, string $environment, string $role, ?string $requestId): array
    {
        $connection = PlatformMonitoringConnection::settings();
        $end = time();
        $result = ['state' => 'not_configured', 'minutes' => $minutes, 'sampleLimit' => self::LIMIT, 'sampleSize' => 0,
            'limitReached' => false, 'observedAt' => gmdate(DATE_ATOM, $end), 'events' => []];
        if ($connection === null) {
            return $result;
        }
        $query = '{'.$connection['label'].'="'.self::SERVICE.'"} | json | __error__=""';
        if ($environment !== 'all') {
            $query .= ' | extra_environment="'. $environment.'"';
        }
        if ($role !== 'all') {
            $query .= ' | extra_service_role="'. $role.'"';
        }
        if ($requestId !== null) {
            $query .= ' | context_request_id="'.$requestId.'" or context_related_request_id="'.$requestId.'"';
        }
        try {
            $response = Http::withToken($connection['token'])->acceptJson()->withOptions(['allow_redirects' => false])
                ->connectTimeout(2)->timeout(8)
                ->get($connection['endpoint'].'query_range', [
                    'query' => $query, 'start' => (string) ($end - $minutes * 60).'000000000',
                    'end' => (string) $end.'000000000', 'limit' => self::LIMIT, 'direction' => 'backward',
                ]);
            if (! $response->successful() || strlen($response->body()) > 4 * 1024 * 1024
                || $response->json('status') !== 'success' || $response->json('data.resultType') !== 'streams'
                || ! is_array($response->json('data.result'))) {
                return array_replace($result, ['state' => 'unavailable']);
            }
            $events = [];
            $received = 0;
            foreach ($response->json('data.result') as $stream) {
                foreach (($stream['values'] ?? []) as $value) {
                    if (++$received > self::LIMIT) {
                        break 2;
                    }
                    $event = self::project($value);
                    if ($event !== null
                        && ($environment === 'all' || $event['environment'] === $environment)
                        && ($role === 'all' || $event['serviceRole'] === $role)
                        && ($requestId === null || in_array($requestId, [$event['requestId'], $event['relatedRequestId']], true))) {
                        $events[] = $event;
                    }
                }
            }
            usort($events, static fn (array $a, array $b): int => strcmp($b['timestamp'], $a['timestamp']));

            return array_replace($result, ['state' => 'ready', 'sampleSize' => count($events), 'limitReached' => $received >= self::LIMIT, 'events' => $events]);
        } catch (Throwable) {
            // No remote response, exception, credentials, or arbitrary log lines exposed.
            return array_replace($result, ['state' => 'unavailable']);
        }
    }

    private static function project(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value[0] ?? null) || ! preg_match('/\A[0-9]{19}\z/D', $value[0])
            || ! is_string($value[1] ?? null) || strlen($value[1]) > 65536) {
            return null;
        }
        $line = json_decode($value[1], true);
        if (! is_array($line) || ($line['extra']['service'] ?? null) !== self::SERVICE || ($line['extra']['schema_version'] ?? null) !== 1) {
            return null;
        }
        $message = $line['message'] ?? null;
        if (! in_array($message, array_merge(SecurityLog::EVENTS, [FrontendErrorLog::EVENT, 'pms.logging.smoke', 'pms.api.request.completed', 'pms.application.exception', 'pms.application.log']), true)) {
            return null;
        }
        $context = is_array($line['context'] ?? null) ? $line['context'] : [];
        $extra = $line['extra'];
        $level = $line['level_name'] ?? null;
        $environment = $extra['environment'] ?? null;
        $route = $context['route_template'] ?? null;
        $uuid = static fn ($id): ?string => is_string($id) && preg_match(FrontendErrorLog::REQUEST_ID_PATTERN, $id) ? $id : null;
        $number = static fn ($n, int $max): ?int => is_int($n) && $n >= 0 && $n <= $max ? $n : null;

        return [
            'timestamp' => gmdate('Y-m-d\TH:i:s', (int) substr($value[0], 0, 10)).'.'.substr($value[0], 10, 3).'Z',
            'event' => $message,
            'level' => in_array($level, ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true) ? $level : 'UNKNOWN',
            'environment' => in_array($environment, ['local', 'testing', 'staging', 'production'], true) ? $environment : 'other',
            'serviceRole' => ServiceRole::normalize($extra['service_role'] ?? null),
            'requestId' => $uuid($context['request_id'] ?? null),
            'relatedRequestId' => $uuid($context['related_request_id'] ?? null),
            'route' => ($message === 'pms.api.request.completed' || ($message === 'pms.security.access.rate_limited' && MonitoringRoutes::contains($route))) && is_string($route) && strlen($route) <= 180 && preg_match('/\A(?:unmatched|api(?:\/[A-Za-z0-9_{}?\-]+)*)\z/D', $route) ? $route : null,
            'statusCode' => $number($context['status_code'] ?? null, 599),
            'durationMs' => $number($context['duration_ms'] ?? null, 86400000),
            'screen' => in_array($context['screen'] ?? null, FrontendErrorLog::SCREENS, true) ? $context['screen'] : null,
        ];
    }
}

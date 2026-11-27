<?php

declare(strict_types=1);

namespace App\Logging;

final class OtlpLogPayload
{
    /**
     * Convert the already-redacted native Loki batch into OTLP/HTTP JSON.
     * Keep the safe JSON line as the body for LogQL's json parser. Never
     * copy raw exception objects or request data into resource attributes.
     */
    public static function fromStreams(array $streams): array
    {
        $groups = [];
        foreach ($streams as $stream) {
            $environment = $stream['stream']['environment'];
            $severity = $stream['stream']['level'];
            $role = ServiceRole::normalize($stream['stream']['service_role'] ?? null);
            $key = $environment.':'.$role;
            $groups[$key] ??= [
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'friendship-on-hotel-backend']],
                    ['key' => 'deployment.environment.name', 'value' => ['stringValue' => $environment]],
                    ['key' => 'service.role', 'value' => ['stringValue' => $role]],
                ]],
                'scopeLogs' => [[
                    'scope' => ['name' => 'friendship-on-hotel.logging'],
                    'logRecords' => [],
                ]],
            ];
            foreach ($stream['values'] as [$timestamp, $line]) {
                $groups[$key]['scopeLogs'][0]['logRecords'][] = [
                    'timeUnixNano' => $timestamp,
                    'severityNumber' => match ($severity) {
                        'debug' => 5,
                        'info' => 9,
                        'notice' => 10,
                        'warning' => 13,
                        'error' => 17,
                        'critical' => 18,
                        'alert' => 21,
                        'emergency' => 24,
                        default => 0,
                    },
                    'severityText' => strtoupper($severity),
                    'body' => ['stringValue' => $line],
                ];
            }
        }
        foreach ($groups as &$group) {
            usort($group['scopeLogs'][0]['logRecords'], static fn (array $a, array $b): int =>
                strcmp($a['timeUnixNano'], $b['timeUnixNano']));
        }
        unset($group);

        return ['resourceLogs' => array_values($groups)];
    }

    public static function fullyAccepted(string $body): bool
    {
        // OTLP success uses HTTP 200 and a JSON ExportLogsServiceResponse.
        // A partialSuccess response may reject records even with HTTP 200.
        $decoded = json_decode($body);
        if (! is_object($decoded)) {
            return false;
        }
        if (! property_exists($decoded, 'partialSuccess')) {
            return true;
        }
        if (! is_object($decoded->partialSuccess)) {
            return false;
        }
        $rejected = $decoded->partialSuccess->rejectedLogRecords ?? 0;

        return $rejected === 0 || $rejected === '0';
    }
}

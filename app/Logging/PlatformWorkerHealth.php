<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PlatformWorkerHealth
{
    private const STALE_SECONDS = 180;

    public function snapshot(): array
    {
        $now = now();
        $heartbeats = [];
        try {
            $rows = DB::table('platform_heartbeats')->whereIn('name', PlatformHeartbeat::NAMES)->get()->keyBy('name');
            foreach (PlatformHeartbeat::NAMES as $name) {
                $row = $rows->get($name);
                $timestamp = $row ? (int) $row->observed_at : null;
                $age = $timestamp === null ? null : $now->timestamp - $timestamp;
                $state = $age === null ? 'never_seen' : ($age < 0 ? 'unknown' : ($age > self::STALE_SECONDS ? 'stale' : 'recent'));
                $heartbeats[$name] = [
                    'state' => $state, 'observedAt' => $timestamp === null ? null : gmdate(DATE_ATOM, $timestamp),
                    'ageSeconds' => $age === null || $age < 0 ? null : $age,
                    'outcome' => $row && in_array($row->outcome, ['observed', 'started', 'succeeded', 'failed', 'disabled'], true) ? $row->outcome : null,
                    'serviceRole' => $row ? ServiceRole::normalize($row->service_role) : null,
                ];
            }
        } catch (Throwable) {
            foreach (PlatformHeartbeat::NAMES as $name) {
                $heartbeats[$name] = ['state' => 'unknown', 'observedAt' => null, 'ageSeconds' => null, 'outcome' => null, 'serviceRole' => null];
            }
        }

        return [
            'observedAt' => $now->toIso8601String(), 'staleAfterSeconds' => self::STALE_SECONDS,
            'heartbeats' => $heartbeats, 'push' => $this->push($now), 'queue' => $this->queue($now),
        ];
    }

    private function push($now): array
    {
        $empty = ['state' => 'unavailable', 'ready' => null, 'delayed' => null, 'processing' => null, 'expiredLeases' => null,
            'sent24h' => null, 'failed24h' => null, 'oldestReadySeconds' => null];
        try {
            $table = DB::table('push_deliveries');
            $pending = (clone $table)->where('status', 'pending');
            // Due includes work awaiting recovery/expiration, intentionally
            // matching the persisted outbox rather than claiming all are sendable.
            $ready = (clone $pending)->where('available_at', '<=', $now);
            $oldest = (clone $ready)->min('available_at');
            $processing = (clone $table)->where('status', 'processing');
            $oldestSeconds = $oldest === null ? null : max(0, $now->timestamp - \Illuminate\Support\Carbon::parse($oldest)->timestamp);

            return [
                'state' => 'ready', 'ready' => $ready->count(), 'delayed' => (clone $pending)->where('available_at', '>', $now)->count(),
                'processing' => $processing->count(), 'expiredLeases' => (clone $processing)->where('available_at', '<=', $now)->count(),
                'sent24h' => (clone $table)->where('status', 'sent')->where('updated_at', '>=', $now->copy()->subDay())->count(),
                'failed24h' => (clone $table)->where('status', 'failed')->where('updated_at', '>=', $now->copy()->subDay())->count(),
                'oldestReadySeconds' => $oldestSeconds,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    private function queue($now): array
    {
        $settings = config('queue.connections.'.config('queue.default'), []);
        $driver = $settings['driver'] ?? null;
        $driver = in_array($driver, ['sync', 'database'], true) ? $driver : 'other';
        $result = ['state' => $driver === 'sync' ? 'not_applicable' : 'unavailable', 'driver' => $driver,
            'ready' => null, 'delayed' => null, 'reserved' => null, 'expiredReservations' => null, 'oldestReadySeconds' => null,
            'failed24h' => null, 'failedState' => 'not_configured'];
        if ($driver === 'database') {
            try {
                $connection = DB::connection($settings['connection'] ?? null);
                $table = (string) ($settings['table'] ?? 'jobs');
                if (Schema::connection($connection->getName())->hasTable($table)) {
                    $jobs = $connection->table($table);
                    $ready = (clone $jobs)->whereNull('reserved_at')->where('available_at', '<=', $now->timestamp);
                    $reserved = (clone $jobs)->whereNotNull('reserved_at');
                    $oldest = (clone $ready)->min('available_at');
                    $result = array_replace($result, [
                        'state' => 'ready', 'ready' => $ready->count(),
                        'delayed' => (clone $jobs)->whereNull('reserved_at')->where('available_at', '>', $now->timestamp)->count(),
                        'reserved' => $reserved->count(),
                        'expiredReservations' => (clone $reserved)->where('reserved_at', '<=', $now->timestamp - max(1, (int) ($settings['retry_after'] ?? 90)))->count(),
                        'oldestReadySeconds' => $oldest === null ? null : max(0, $now->timestamp - (int) $oldest),
                    ]);
                }
            } catch (Throwable) {
                // Unsupported or missing queue storage is not zero backlog.
            }
        }
        $failed = config('queue.failed', []);
        if (in_array($failed['driver'] ?? null, ['database', 'database-uuids'], true)) {
            $result['failedState'] = 'unavailable';
            try {
                $result['failed24h'] = DB::connection($failed['database'] ?? null)->table($failed['table'] ?? 'failed_jobs')
                    ->where('failed_at', '>=', $now->copy()->subDay())->count();
                $result['failedState'] = 'ready';
            } catch (Throwable) {
                // Never return job payloads or exception text.
            }
        }

        return $result;
    }
}

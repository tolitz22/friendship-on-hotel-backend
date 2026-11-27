<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Throwable;

final class PlatformHeartbeat
{
    public const NAMES = ['scheduler', 'queue_worker', 'push_started', 'push_completed'];

    private ?int $lastQueueWrite = null;

    public function queueTick(): void
    {
        $now = now()->timestamp;
        if ($this->lastQueueWrite !== null && $now >= $this->lastQueueWrite && $now - $this->lastQueueWrite < 60) {
            return;
        }
        $this->lastQueueWrite = $now;
        $this->record('queue_worker', 'observed');
    }

    public function record(string $name, string $outcome): void
    {
        if (! in_array($name, self::NAMES, true) || ! in_array($outcome, ['observed', 'started', 'succeeded', 'failed', 'disabled'], true)) {
            return;
        }
        try {
            DB::table('platform_heartbeats')->upsert([[
                'name' => $name, 'observed_at' => now()->timestamp, 'outcome' => $outcome,
                'service_role' => ServiceRole::normalize(config('logging.service_role')),
            ]], ['name'], ['observed_at', 'outcome', 'service_role']);
        } catch (Throwable) {
            // Monitoring must not prevent job execution, scheduler work or
            // application startup during a rolling deployment.
            error_log('pms.platform.heartbeat_write_failed');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Throwable;

final class WorkerLogFlush
{
    public static function flush(): void
    {
        if (! config('logging.channels.loki.with.enabled', false)) {
            return;
        }

        // The default stack and direct Loki channel may share a handler.
        // Do not create new channels, clear context, or reset unrelated handlers.
        $seen = [];
        try {
            foreach (Log::getChannels() as $channel) {
                $logger = $channel->getLogger();
                if (! $logger instanceof Logger) {
                    continue;
                }
                foreach ($logger->getHandlers() as $handler) {
                    if (! $handler instanceof BufferedLokiHandler) {
                        continue;
                    }
                    $id = spl_object_id($handler);
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $handler->close();
                }
            }
        } catch (Throwable) {
            // Telemetry must never change job success, retry or acknowledgement.
            error_log('pms.loki.worker_flush_failed');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\BufferedLokiHandler;
use App\Logging\LokiSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CheckLokiLogging extends Command
{
    protected $signature = 'pms:loki-check {--send : Submit one synthetic smoke event}';
    protected $description = 'Check Loki exporter configuration without displaying credentials';

    public function handle(): int
    {
        $options = config('logging.channels.loki.with', []);
        if (! ($options['enabled'] ?? false)) {
            $this->line('Loki exporter: disabled. Railway stderr logging is unchanged.');

            return $this->option('send') ? self::FAILURE : self::SUCCESS;
        }
        if (! LokiSettings::valid((string) ($options['url'] ?? ''), (string) ($options['username'] ?? ''), (string) ($options['token'] ?? ''))
            || config('logging.default') !== 'stack'
            || ! in_array('stderr', (array) config('logging.channels.stack.channels'), true)
            || ! in_array('loki', (array) config('logging.channels.stack.channels'), true)) {
            $this->error('NO-GO: valid HTTPS Loki settings and stack with stderr,loki are required.');

            return self::FAILURE;
        }
        $this->line('Loki configuration: valid. Network delivery not yet verified.');
        if (! $this->option('send')) {
            return self::SUCCESS;
        }
        try {
            $logger = Log::channel('loki');
            $logger->warning('pms.logging.smoke', ['count' => 1]);
            foreach ($logger->getLogger()->getHandlers() as $handler) {
                if ($handler instanceof BufferedLokiHandler) {
                    $handler->close();
                }
            }
        } catch (Throwable) {
            $this->error('Smoke event could not be submitted.');

            return self::FAILURE;
        }
        $this->line('Smoke event submitted to best-effort exporter. Confirm its presence in Grafana Explore; this is not a delivery acknowledgement.');

        return self::SUCCESS;
    }
}

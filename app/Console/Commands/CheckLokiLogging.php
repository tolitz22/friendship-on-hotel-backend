<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\BufferedLokiHandler;
use App\Logging\LokiSettings;
use App\Logging\ServiceRole;
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
        $protocol = (string) ($options['protocol'] ?? 'loki');
        if (! LokiSettings::valid((string) ($options['url'] ?? ''), (string) ($options['username'] ?? ''), (string) ($options['token'] ?? ''), $protocol)
            || config('logging.default') !== 'stack'
            || ! in_array('stderr', (array) config('logging.channels.stack.channels'), true)
            || ! in_array('loki', (array) config('logging.channels.stack.channels'), true)) {
            $this->error('NO-GO: valid HTTPS Loki settings and stack with stderr,loki are required.');

            return self::FAILURE;
        }
        $this->line('Loki configuration: valid. Protocol: '.$protocol.'. Network delivery not yet verified.');
        $role = ServiceRole::normalize(config('logging.service_role'));
        $this->line('Service role: '.$role);
        if ($role === 'unspecified') {
            $this->warn('Set PMS_LOG_SERVICE_ROLE to backend, worker or scheduler to separate service logs.');
        }
        if (! $this->option('send')) {
            return self::SUCCESS;
        }
        try {
            $logger = Log::channel('loki');
            $accepted = false;
            $diagnostic = ['outcome' => 'handler_not_found', 'http_status' => null];
            $logger->warning('pms.logging.smoke', ['count' => 1]);
            foreach ($logger->getLogger()->getHandlers() as $handler) {
                if ($handler instanceof BufferedLokiHandler) {
                    $handler->close();
                    $accepted = $handler->lastDeliveryAccepted() === true;
                    $diagnostic = $handler->deliveryDiagnostic();
                }
            }
        } catch (Throwable) {
            $this->error('Smoke event could not be submitted.');

            return self::FAILURE;
        }
        if (! $accepted) {
            $this->line('Export outcome: '.$diagnostic['outcome']);
            $this->line('HTTP status: '.($diagnostic['http_status'] ?? 'not received'));
            $this->error('NO-GO: smoke event not acknowledged. Check protocol, endpoint, token permissions, log level and connectivity.');

            return self::FAILURE;
        }
        $this->line('Smoke event acknowledged by remote endpoint. Confirm its presence in Grafana Explore.');

        return self::SUCCESS;
    }
}

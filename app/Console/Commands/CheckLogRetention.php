<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Logging\LokiSettings;

final class CheckLogRetention extends Command
{
    protected $signature = 'pms:logs-retention-check';
    protected $description = 'Inspect technical log retention configuration without modifying files or business records';

    public function handle(): int
    {
        $default = (string) config('logging.default');
        $channels = $default === 'stack' ? (array) config('logging.channels.stack.channels', []) : [$default];
        $safe = true;
        $this->line('Technical logging destination: '.implode(', ', array_map(
            fn ($channel) => in_array($channel, ['stderr', 'pms_daily', 'single', 'null', 'loki'], true) ? $channel : 'unrecognized',
            $channels
        )));
        if ($channels === []) {
            $safe = false;
            $this->warn('No active logging channels.');
        }
        foreach ($channels as $channel) {
            if ($channel === 'stderr') {
                $this->line('stderr: retention and archival are controlled by the hosting/logging provider.');
            } elseif ($channel === 'pms_daily') {
                $this->line('Dedicated rotating log files: maximum '.(int) config('logging.channels.pms_daily.days').' daily files.');
                $this->line('Local archive export is explicit; no business history is affected.');
            } elseif ($channel === 'loki') {
                $options = config('logging.channels.loki.with', []);
                if (! ($options['enabled'] ?? false) || ! LokiSettings::valid(
                    (string) ($options['url'] ?? ''), (string) ($options['username'] ?? ''), (string) ($options['token'] ?? ''), (string) ($options['protocol'] ?? 'loki')
                ) || ! in_array('stderr', $channels, true)) {
                    $this->warn('Loki requires enabled valid configuration and stderr fallback.');
                    $safe = false;
                } else {
                    $this->line('Loki: retention is controlled by the external log provider.');
                }
            } elseif ($channel === 'single') {
                $this->warn('Legacy single-file channel is unbounded; prefer stderr or pms_daily.');
                $safe = false;
            } else {
                $this->warn('A disabled or unrecognized logging channel requires operator review.');
                $safe = false;
            }
        }
        $this->line('Business audit records and financial journals: excluded from technical log rotation and archival.');
        $this->line('No files or database records were modified.');

        return $safe ? self::SUCCESS : self::FAILURE;
    }
}

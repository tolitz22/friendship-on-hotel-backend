<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
            fn ($channel) => in_array($channel, ['stderr', 'pms_daily', 'single', 'null'], true) ? $channel : 'unrecognized',
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

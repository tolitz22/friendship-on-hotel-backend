<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class CheckStructuredLogging extends Command
{
    protected $signature = 'pms:logging-check';
    protected $description = 'Emit a synthetic structured logging event without touching hotel records';

    public function handle(): int
    {
        Log::info('pms.logging.smoke', ['count' => 1]);

        $this->info('Logging smoke event submitted to the configured channel. Check the destination for pms.logging.smoke (INFO must be enabled).');

        return self::SUCCESS;
    }
}

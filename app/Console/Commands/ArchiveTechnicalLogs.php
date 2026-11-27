<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\TechnicalLogArchive;
use Illuminate\Console\Command;
use Throwable;

final class ArchiveTechnicalLogs extends Command
{
    protected $signature = 'pms:logs-archive {--write : Explicitly create compressed copies}';
    protected $description = 'Preview or copy completed dedicated technical logs to private local storage; never delete sources';

    public function handle(TechnicalLogArchive $archive): int
    {
        $source = storage_path('logs');
        $destination = storage_path('app/private/pms-log-archives');
        $today = now()->toDateString();
        $files = $archive->candidates($source, $today);
        $this->line('Eligible completed daily logs (up to ten): '.count($files));
        if (! $this->option('write')) {
            $this->line('Dry run. No files or database records modified. Use --write to create private gzip copies.');

            return self::SUCCESS;
        }
        // Avoid concurrent archive commands within the same filesystem.
        $lockPath = storage_path('logs/.pms-archive.lock');
        if (is_link($lockPath)) {
            $this->error('Unsafe archive lock path.');

            return self::FAILURE;
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            $this->error('Could not open archive lock.');

            return self::FAILURE;
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->warn('Another archive command is running.');

                return self::FAILURE;
            }
            $created = 0;
            foreach ($files as $name) {
                if ($archive->archive($source, $destination, $name, $today)) {
                    $created++;
                }
            }
            $this->line('Created '.$created.' private gzip copies. No source files or database records deleted.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Archive stopped; check available private storage, file eligibility and capacity.');

            return self::FAILURE;
        } finally {
            fclose($lock);
        }
    }
}

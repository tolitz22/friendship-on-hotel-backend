<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Finance\FinancialIntegrityDiagnostics;
use App\Services\Finance\NoShowJournalIntegrityService;
use Illuminate\Console\Command;

final class InspectFinancialIntegrity extends Command
{
    protected $signature = 'pms:financial-integrity {--hotel=} {--format=text}';

    protected $description = 'Read-only financial integrity diagnostics for one hotel';

    public function handle(FinancialIntegrityDiagnostics $service): int
    {
        $id = NoShowJournalIntegrityService::sourceId('', (string) $this->option('hotel'));
        $format = $this->option('format');
        if ($id === null || !in_array($format, ['text', 'json'], true)) {
            $this->error('A valid --hotel and --format=text or json are required.');

            return self::INVALID;
        }
        $hotel = Hotel::query()->find($id);
        if ($hotel === null) {
            $this->error('Hotel not found.');

            return self::FAILURE;
        }
        $report = $service->report($hotel);
        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line('Hotel: '.$report['hotelId'].'; business date: '.($report['businessDate'] ?? 'not configured'));
            $this->line('Checks: '.implode(', ', $report['checks']));
            foreach ($report['issues'] as $issue) {
                $this->line(json_encode($issue, JSON_THROW_ON_ERROR));
            }
            $this->line($report['integrityValid'] ? 'Implemented checks passed.' : 'Integrity issues found.');
            if ($report['dailyClosing'] !== null) {
                $this->line($report['dailyClosing']['canClose'] ? 'Daily Closing: ready (advisory).' : 'Daily Closing: blocked.');
                foreach ($report['dailyClosing']['blockers'] as $blocker) {
                    $this->line(' - '.$blocker);
                }
            }
            $this->line('Not checked: '.implode(', ', $report['notChecked']));
            $this->line('Read-only; not a closing or release certification.');
        }

        return $report['integrityValid'] ? self::SUCCESS : self::FAILURE;
    }
}

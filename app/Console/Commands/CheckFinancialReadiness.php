<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Finance\FinancialIntegrityDiagnostics;
use App\Services\Finance\FinancialReadinessGate;
use App\Services\Finance\NoShowJournalIntegrityService;
use Illuminate\Console\Command;

final class CheckFinancialReadiness extends Command
{
    protected $signature = 'pms:financial-readiness {--hotel=}';

    protected $description = 'Evaluate read-only financial release prerequisites for one hotel';

    public function handle(FinancialIntegrityDiagnostics $diagnostics): int
    {
        $id = NoShowJournalIntegrityService::sourceId('', (string) $this->option('hotel'));
        if ($id === null) {
            $this->error('A valid --hotel is required.');

            return self::INVALID;
        }
        $hotel = Hotel::query()->find($id);
        if ($hotel === null) {
            $this->error('Hotel not found.');

            return self::FAILURE;
        }
        $report = $diagnostics->report($hotel);
        $report['readiness'] = FinancialReadinessGate::evaluate($report);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $report['readiness']['financialGatePassed'] ? self::SUCCESS : self::FAILURE;
    }
}

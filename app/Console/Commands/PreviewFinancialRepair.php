<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Finance\FinancialIntegrityDiagnostics;
use App\Services\Finance\NoShowJournalIntegrityService;
use Illuminate\Console\Command;

final class PreviewFinancialRepair extends Command
{
    protected $signature = 'pms:financial-repair {--hotel=} {--dry-run}';

    protected $description = 'Generate read-only financial investigation proposals; never post corrections';

    public function handle(FinancialIntegrityDiagnostics $service): int
    {
        $id = NoShowJournalIntegrityService::sourceId('', (string) $this->option('hotel'));
        if ($id === null || !$this->option('dry-run')) {
            $this->error('A valid --hotel and explicit --dry-run are required.');

            return self::INVALID;
        }
        $hotel = Hotel::query()->find($id);
        if ($hotel === null) {
            $this->error('Hotel not found.');

            return self::FAILURE;
        }
        $report = $service->report($hotel);
        $report['proposals'] = array_map(static fn (array $issue): array => [
            'finding' => $issue,
            'action' => 'Investigate source evidence and reconcile against original records before proposing an authorized correction.',
            'approvalRequired' => true,
            'executable' => false,
        ], $report['issues']);
        $report['recordsModified'] = false;
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $report['integrityValid'] ? self::SUCCESS : self::FAILURE;
    }
}

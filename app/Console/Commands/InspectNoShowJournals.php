<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Finance\NoShowJournalIntegrityService;
use Illuminate\Console\Command;

final class InspectNoShowJournals extends Command
{
    protected $signature = 'finance:inspect-no-show-journals {hotelId}';
    protected $description = 'Inspect one hotel for orphaned and duplicate no-show journals without changing records';

    public function handle(NoShowJournalIntegrityService $service): int
    {
        $id = NoShowJournalIntegrityService::sourceId('', (string) $this->argument('hotelId'));
        $hotel = $id === null ? null : Hotel::query()->find($id);
        if ($hotel === null) {
            $this->error('Hotel not found.');
            return self::FAILURE;
        }
        $report = $service->report($hotel);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return $report['integrityValid'] ? self::SUCCESS : self::FAILURE;
    }
}

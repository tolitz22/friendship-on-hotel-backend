<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reservations\NoShowWorkflow;
use App\Models\Hotel;
use App\Services\Finance\NoShowJournalIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class InspectNoShowPilot extends Command
{
    protected $signature = 'finance:inspect-no-show-pilot {hotelId}';
    protected $description = 'Read-only configuration and dependency inspection for a single no-show pilot hotel';

    public function handle(NoShowJournalIntegrityService $integrity): int
    {
        $id = NoShowJournalIntegrityService::sourceId('', (string) $this->argument('hotelId'));
        $hotel = $id === null ? null : Hotel::query()->find($id);
        if ($hotel === null) {
            $this->error('Hotel not found.');
            return self::FAILURE;
        }

        $keys = ['enable_verified_settlement', 'enable_guest_deposit_posting',
            'enable_no_show_allocation_posting', 'enable_no_show_refund_posting',
            'enable_no_show_room_correction_review', 'enable_no_show_room_correction_posting',
            'enable_no_show_fee_correction_review', 'enable_no_show_fee_correction_posting',
            'enable_no_show_closing_integrity', 'enable_cash_guest_deposit_posting',
            'enable_historical_guest_deposit_posting'];
        $flags = [];
        foreach ($keys as $key) $flags[$key] = (bool) config('financial_accounting.'.$key, false);
        $allowlist = array_values(array_unique(array_filter(array_map('trim',
            explode(',', (string) config('booking.no_show_adjustments_hotel_ids', ''))),
            static fn (string $value): bool => $value !== '')));
        $issues = [];
        if ($allowlist !== [(string) $hotel->id]) $issues[] = 'Allowlist is not restricted to the selected hotel.';
        if (!NoShowWorkflow::enabled((int) $hotel->id)) $issues[] = 'No-show workflow is not enabled for the selected hotel.';
        foreach ([
            'enable_verified_settlement',
            'enable_guest_deposit_posting',
            'enable_no_show_allocation_posting',
            'enable_no_show_refund_posting',
            'enable_no_show_fee_correction_review',
            'enable_no_show_fee_correction_posting',
            'enable_no_show_closing_integrity',
        ] as $requiredFlag) {
            if (!$flags[$requiredFlag]) $issues[] = $requiredFlag.' is required for pilot acceptance.';
        }
        foreach ([
            'enable_guest_deposit_posting' => ['enable_verified_settlement'],
            'enable_no_show_allocation_posting' => ['enable_guest_deposit_posting', 'enable_verified_settlement'],
            'enable_no_show_refund_posting' => ['enable_no_show_allocation_posting', 'enable_guest_deposit_posting', 'enable_verified_settlement'],
            'enable_no_show_room_correction_posting' => ['enable_no_show_room_correction_review'],
            'enable_no_show_fee_correction_posting' => ['enable_no_show_fee_correction_review'],
            'enable_cash_guest_deposit_posting' => ['enable_guest_deposit_posting'],
            'enable_historical_guest_deposit_posting' => ['enable_guest_deposit_posting'],
        ] as $feature => $dependencies) {
            foreach ($dependencies as $dependency) {
                if ($flags[$feature] && !$flags[$dependency]) $issues[] = $feature.' requires '.$dependency.'.';
            }
        }
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->first();
        $date = $clock?->business_date;
        $periodCount = $date === null ? 0 : DB::table('financial_accounting_periods')->where('hotel_id', $hotel->id)
            ->where('status', 'open')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->count();
        $admins = DB::table('hotel_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.hotel_id', $hotel->id)->where('m.role', 'admin')->where('m.is_active', true)->where('u.is_active', true)
            ->distinct()->count('m.user_id');
        if ($hotel->currency !== 'PHP') $issues[] = 'Pilot requires PHP currency.';
        if ($clock === null || $clock->status !== 'open' || DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->where('business_date', $date)->exists()) $issues[] = 'Open financial business date required.';
        if ($periodCount !== 1) $issues[] = 'Exactly one open accounting period is required.';
        if ($admins < 2) $issues[] = 'Two active independent administrators are required.';
        $report = $integrity->report($hotel);
        if (!$report['integrityValid']) $issues[] = 'Existing no-show journal integrity issues require reconciliation.';
        $this->line(json_encode([
            'hotelId' => (string) $hotel->id, 'hotelName' => $hotel->name,
            'allowlist' => $allowlist, 'workflowEnabled' => NoShowWorkflow::enabled((int) $hotel->id),
            'flags' => $flags, 'businessDate' => $date, 'openPeriodCount' => $periodCount,
            'activeAdminCount' => $admins, 'journalIntegrityValid' => $report['integrityValid'],
            'issues' => $issues, 'configurationChecksPassed' => $issues === [],
            'readOnly' => true, 'activationCertified' => false,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}

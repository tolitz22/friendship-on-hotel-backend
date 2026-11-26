<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\HotelNotificationService;
use App\Services\PushDeliveryReadiness;
use App\Services\PushDeviceEligibility;
use App\Services\StaffMessageNotifications;
use Illuminate\Console\Command;

final class CheckHotelPush extends Command
{
    protected $signature = 'pms:push-check {--scan : Read a bounded sample of the notification sources without enqueuing or sending}';
    protected $description = 'Read-only push delivery preflight; never sends notifications';

    public function handle(PushDeliveryReadiness $readiness, HotelNotificationService $notifications, PushDeviceEligibility $eligibility, StaffMessageNotifications $messages): int
    {
        $issues = $readiness->configurationIssues();
        $this->line('Delivery switch: '.(config('push.enabled') ? 'enabled' : 'disabled'));
        try {
            $schemaIssues = $readiness->schemaIssues();
            $issues = array_merge($issues, $schemaIssues);
            $hotel = in_array('hotel_id_invalid', $issues, true) ? null : Hotel::find(config('push.hotel_id'));
            if (! $hotel && ! in_array('hotel_id_invalid', $issues, true)) {
                $issues[] = 'hotel_not_found';
            }
            if ($hotel && ! $readiness->hotelAllowed($hotel)) {
                $issues[] = 'non_demo_hotel_not_authorized';
            }
            if ($hotel && $readiness->hotelAllowed($hotel) && $schemaIssues === []) {
                $devices = $eligibility->forHotel($hotel->id);
                $this->line('Eligible devices: '.$devices->count());
                if ($this->option('scan')) {
                    $feed = $notifications->feed($hotel, true);
                    $this->line('Sample hotel alerts: '.count($feed['notifications']));
                    $users = $devices->select('user_id')->distinct()->orderBy('user_id')->limit(10)->pluck('user_id');
                    $personal = 0;
                    foreach ($users as $userId) {
                        foreach ($messages->all($hotel, (int) $userId, 8) as $alert) {
                            $personal++;
                        }
                    }
                    $this->line('Sample personal alerts: '.$personal.' (up to ten recipients)');
                }
            }
        } catch (\Throwable) {
            // Query exceptions can contain record values. Only print a safe code.
            $issues[] = 'database_or_discovery_check_failed';
        }
        foreach (array_unique($issues) as $issue) {
            $this->error($issue);
        }
        if ($issues !== []) {
            $this->error('NO-GO: push preflight did not pass.');

            return self::FAILURE;
        }

        $this->info('Preflight checks passed; actual transport and device acceptance are still required.');

        return self::SUCCESS;
    }
}

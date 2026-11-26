<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HotelNotificationService;
use App\Services\PushDeliveryReadiness;
use App\Services\PushTransport;
use App\Services\PushDeviceEligibility;
use App\Services\StaffMessageNotifications;
use Illuminate\Console\Command;

final class CheckHotelPush extends Command
{
    protected $signature = 'pms:push-check {--scan : Read a bounded sample of the notification sources without enqueuing or sending}';
    protected $description = 'Read-only push delivery preflight; never sends notifications';

    public function handle(PushDeliveryReadiness $readiness, HotelNotificationService $notifications, PushDeviceEligibility $eligibility, StaffMessageNotifications $messages, PushTransport $transport): int
    {
        $issues = $readiness->configurationIssues();
        if ($issues === []) {
            try {
                // Initialize the actual locked transport without making any request.
                $transport->make();
            } catch (\Throwable) {
                $issues[] = 'transport_initialization_failed';
            }
        }
        $this->line('Delivery switch: '.(config('push.enabled') ? 'enabled' : 'disabled'));
        try {
            $schemaIssues = $readiness->schemaIssues();
            $issues = array_merge($issues, $schemaIssues);
            $scopeIssues = $readiness->scopeIssues();
            $issues = array_merge($issues, $scopeIssues);
            if ($scopeIssues === [] && $schemaIssues === []) {
                $hotels = $readiness->hotels();
                $this->line('Hotel scope: '.config('push.scope'));
                $this->line('Hotels in scope: '.$hotels->count());
                $eligible = 0;
                $sampled = 0;
                $hotelAlerts = 0;
                $personal = 0;
                foreach ($hotels->lazyById(100) as $hotel) {
                    $devices = $eligibility->forHotel($hotel->id);
                    $eligible += $devices->count();
                    if ($this->option('scan') && $sampled < 10) {
                        $sampled++;
                        $feed = $notifications->feed($hotel, true);
                        $hotelAlerts += count($feed['notifications']);
                        $users = $devices->select('user_id')->distinct()->orderBy('user_id')->limit(10)->pluck('user_id');
                        foreach ($users as $userId) {
                            foreach ($messages->all($hotel, (int) $userId, 8) as $alert) {
                                $personal++;
                            }
                        }
                    }
                }
                $this->line('Eligible devices: '.$eligible);
                if ($this->option('scan')) {
                    $this->line('Sampled hotels: '.$sampled.' (up to ten)');
                    $this->line('Sample hotel alerts: '.$hotelAlerts);
                    $this->line('Sample personal alerts: '.$personal.' (up to ten recipients per sampled hotel)');
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

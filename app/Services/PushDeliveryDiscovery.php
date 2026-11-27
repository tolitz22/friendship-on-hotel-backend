<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;

final class PushDeliveryDiscovery
{
    public function __construct(
        private readonly HotelNotificationService $notifications,
        private readonly StaffMessageNotifications $messages,
        private readonly PushDeviceEligibility $eligibility,
        private readonly PushPreferences $preferences,
        private readonly PushDeliveryQueue $queue,
    ) {}

    public function discover(Hotel $hotel): void
    {
        // Batch by recipient, not device, so private message queries run once per user.
        $users = $this->eligibility->forHotel($hotel->id)->select('user_id')->distinct()
            ->lazyById(100, 'user_id')->chunk(100);
        foreach ($users as $batch) {
            $userIds = $batch->pluck('user_id')->all();
            $preferences = $this->preferences->forUsers($hotel->id, $userIds);
            $enabledUsers = array_keys(array_filter($preferences, fn ($value) => $value['enabled']));
            if ($enabledUsers === []) {
                continue;
            }
            $devices = $this->eligibility->forHotel($hotel->id)->whereIn('user_id', $enabledUsers)
                ->get(['id', 'user_id'])->groupBy('user_id');
            if ($devices->isEmpty()) {
                continue;
            }
            $this->queue->enqueueBatch((function () use ($hotel, $preferences, $devices): iterable {
                $scope = app(PushRecipientScope::class);
                $roles = [];
                foreach ($devices as $userId => $registered) $roles[$userId] = $scope->role($hotel, (int)$userId);
                foreach ($this->notifications->all($hotel) as $alert) {
                    foreach ($devices as $userId => $registered) {
                        if ($roles[$userId] !== 'admin' || ! ($preferences[$userId][$alert['kind']] ?? false)) {
                            continue;
                        }
                        foreach ($registered as $device) {
                            yield ['deviceId' => $device->id, 'alert' => $alert];
                        }
                    }
                }
                foreach ($devices as $userId => $registered) {
                    $alerts = match ($roles[$userId]) {
                        'admin' => $this->messages->all($hotel, (int) $userId),
                        'housekeeping' => app(HousekeepingNotifications::class)->personal($hotel,(int)$userId,null)['notifications'],
                        'front_desk' => app(OperationalActivityNotifications::class)->recentApprovedInspections($hotel,null),
                        default => [],
                    };
                    foreach ($alerts as $alert) {
                        if (! ($preferences[$userId][$alert['kind']] ?? false)) {
                            continue;
                        }
                        foreach ($registered as $device) {
                            yield ['deviceId' => $device->id, 'alert' => $alert];
                        }
                    }
                }
            })());
        }
    }
}

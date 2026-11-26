<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\HotelNotificationService;
use App\Services\PushPreferences;
use App\Services\PushDeviceEligibility;
use App\Services\PushAlertPayload;
use App\Services\StaffMessageNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class DeliverHotelPush extends Command
{
    protected $signature = 'pms:push-deliver';
    protected $description = 'Discover and deliver hotel alerts outside web requests';

    public function handle(HotelNotificationService $notifications, PushPreferences $preferences, PushDeviceEligibility $eligibility, StaffMessageNotifications $messages): int
    {
        if (! config('push.enabled')) {
            return self::SUCCESS;
        }
        if (! class_exists(WebPush::class) || ! config('push.public_key') || ! config('push.private_key') || ! config('push.subject') || ! config('push.hotel_id')) {
            $this->error('Push delivery requires the Web Push package, VAPID configuration and an explicit pilot hotel.');
            return self::FAILURE;
        }

        $hotel = Hotel::find(config('push.hotel_id'));
        if (! $hotel) {
            return self::FAILURE;
        }
        $sender = new WebPush(['VAPID' => [
            'subject' => config('push.subject'),
            'publicKey' => config('push.public_key'),
            'privateKey' => config('push.private_key'),
        ]], [], 10);
        $eligible = fn () => $eligibility->forHotel($hotel->id);

        foreach ($notifications->all($hotel) as $alert) {
            foreach ($eligible()->lazyById(100) as $device) {
                if (! $preferences->allows($hotel->id, $device->user_id, $alert['kind'])) {
                    continue;
                }
                DB::table('push_deliveries')->insertOrIgnore([
                    'push_subscription_id' => $device->id,
                    'notification_id' => $alert['id'],
                    'kind' => $alert['kind'],
                    'available_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Personal messages are discovered per recipient, never broadcast hotel-wide.
        foreach ($eligible()->lazyById(100) as $device) {
            foreach ($messages->all($hotel, $device->user_id) as $alert) {
                if (! $preferences->allows($hotel->id, $device->user_id, $alert['kind'])) {
                    continue;
                }
                DB::table('push_deliveries')->insertOrIgnore([
                    'push_subscription_id' => $device->id,
                    'notification_id' => $alert['id'],
                    'kind' => $alert['kind'],
                    'available_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // A crashed worker may leave a claimed delivery. Reclaim after a generous lease.
        DB::table('push_deliveries')->where('status', 'processing')->where('available_at', '<=', now())
            ->update(['status' => 'pending', 'updated_at' => now()]);
        $rows = DB::table('push_deliveries')->where('status', 'pending')->where('available_at', '<=', now())
            ->whereIn('push_subscription_id', $eligible()->select('push_subscriptions.id'))
            ->orderBy('id')->limit(100)->get();

        foreach ($rows as $row) {
            if (! DB::table('push_deliveries')->where('id', $row->id)->where('status', 'pending')->where('attempts', $row->attempts)
                ->where('available_at', '<=', now())->update([
                    'status' => 'processing', 'attempts' => $row->attempts + 1,
                    'available_at' => now()->addMinutes(5), 'updated_at' => now(),
                ])) {
                continue;
            }
            $device = $eligible()->find($row->push_subscription_id);
            if (! $device || ! $preferences->allows($hotel->id, $device->user_id, $row->kind)) {
                $this->finish($row, 'cancelled');
                continue;
            }
            $alert = in_array($row->kind, StaffMessageNotifications::KINDS, true)
                ? $messages->current($hotel, $device->user_id, $row->notification_id)
                : $notifications->current($hotel, $row->notification_id);
            if (! $alert || $alert['kind'] !== $row->kind) {
                $this->finish($row, 'cancelled');
                continue;
            }
            try {
                $report = $sender->sendOneNotification(Subscription::create([
                    'endpoint' => $device->endpoint, 'publicKey' => $device->p256dh,
                    'authToken' => $device->auth, 'contentEncoding' => 'aes128gcm',
                ]), json_encode(PushAlertPayload::make($hotel->id, $alert), JSON_THROW_ON_ERROR), ['TTL' => 300, 'topic' => substr(hash('sha256', $hotel->id.':'.$alert['id']), 0, 32)]);
                if ($report->isSubscriptionExpired()) {
                    $device->delete();
                    continue;
                }
                $this->finish($row, $report->isSuccess() ? 'sent' : 'pending');
            } catch (\Throwable) {
                // Never log subscription endpoints or cryptographic material.
                $this->finish($row, 'pending');
            }
        }

        return self::SUCCESS;
    }

    private function finish(object $row, string $status): void
    {
        $attempt = $row->attempts + 1;
        if ($status === 'pending' && $attempt >= 5) {
            $status = 'failed';
        }
        DB::table('push_deliveries')->where('id', $row->id)->where('status', 'processing')
            ->where('attempts', $attempt)->update([
                'status' => $status, 'available_at' => now()->addMinutes(2 ** min($attempt, 5)),
                'updated_at' => now(),
            ]);
    }
}

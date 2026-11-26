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
use App\Services\PushDeliveryQueue;
use App\Services\PushDeliveryDiscovery;
use App\Services\PushDeliveryOutcome;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class DeliverHotelPush extends Command
{
    protected $signature = 'pms:push-deliver';
    protected $description = 'Discover and deliver hotel alerts outside web requests';

    public function handle(HotelNotificationService $notifications, PushPreferences $preferences, PushDeviceEligibility $eligibility, StaffMessageNotifications $messages, PushDeliveryQueue $queue, PushDeliveryDiscovery $discovery): int
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

        $discovery->discover($hotel);

        $queue->recover($hotel->id);
        foreach ($queue->ready($hotel->id) as $row) {
            if (! $queue->claim($row)) {
                continue;
            }
            $device = $eligible()->find($row->push_subscription_id);
            if (! $device || ! $preferences->allows($hotel->id, $device->user_id, $row->kind)) {
                $queue->finish($row, 'cancelled', 'no_longer_eligible');
                continue;
            }
            $alert = in_array($row->kind, StaffMessageNotifications::KINDS, true)
                ? $messages->current($hotel, $device->user_id, $row->notification_id)
                : $notifications->current($hotel, $row->notification_id);
            if (! $alert || $alert['kind'] !== $row->kind) {
                $queue->finish($row, 'cancelled', 'stale_alert');
                continue;
            }
            try {
                $report = $sender->sendOneNotification(Subscription::create([
                    'endpoint' => $device->endpoint, 'publicKey' => $device->p256dh,
                    'authToken' => $device->auth, 'contentEncoding' => 'aes128gcm',
                ]), json_encode(PushAlertPayload::make($hotel->id, $alert), JSON_THROW_ON_ERROR), ['TTL' => 300, 'topic' => substr(hash('sha256', $hotel->id.':'.$alert['id']), 0, 32)]);
                $response = $report->getResponse();
                [$status, $code] = PushDeliveryOutcome::classify($response?->getStatusCode(), $report->isSuccess());
                if ($status === 'expired') {
                    // Expired endpoints cannot be reused. Remove this subscription
                    // only if this worker still owns the delivery claim.
                    if ($queue->finish($row, 'failed', $code)) {
                        $device->delete();
                    }
                    continue;
                }
                $retry = $response?->getHeaderLine('Retry-After') ?? '';
                $delay = ctype_digit($retry) ? (int) $retry : ($retry !== '' ? max(0, (strtotime($retry) ?: 0) - time()) : null);
                $queue->finish($row, $status, $code, $delay);
            } catch (\Throwable) {
                // Never log provider bodies, subscription endpoints or key material.
                $queue->finish($row, 'pending', 'transport_exception');
            }
        }

        $this->line('Push delivery status: '.json_encode($queue->counts($hotel->id), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

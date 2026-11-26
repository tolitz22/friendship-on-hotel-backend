<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\PushDeliveryQueue;
use App\Services\PushDeliveryReadiness;
use Illuminate\Console\Command;

final class PruneHotelPush extends Command
{
    protected $signature = 'pms:push-prune';
    protected $description = 'Prune old terminal push deliveries for immutable events';

    public function handle(PushDeliveryQueue $queue, PushDeliveryReadiness $readiness): int
    {
        if (! config('push.enabled') || ! config('push.hotel_id')) {
            return self::SUCCESS;
        }
        $hotel = Hotel::find(config('push.hotel_id'));
        if (! $hotel || ! $readiness->hotelAllowed($hotel)) {
            $this->error('Push pruning hotel is outside the authorized pilot scope.');
            return self::FAILURE;
        }
        $removed = $queue->prune($hotel->id);
        $this->line('Pruned '.$removed.' terminal push deliveries.');

        return self::SUCCESS;
    }
}

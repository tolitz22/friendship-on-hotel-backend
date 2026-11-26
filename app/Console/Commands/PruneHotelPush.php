<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PushDeliveryQueue;
use Illuminate\Console\Command;

final class PruneHotelPush extends Command
{
    protected $signature = 'pms:push-prune';
    protected $description = 'Prune old terminal push deliveries for immutable events';

    public function handle(PushDeliveryQueue $queue): int
    {
        if (! config('push.enabled') || ! config('push.hotel_id')) {
            return self::SUCCESS;
        }
        $removed = $queue->prune((int) config('push.hotel_id'));
        $this->line('Pruned '.$removed.' terminal push deliveries.');

        return self::SUCCESS;
    }
}

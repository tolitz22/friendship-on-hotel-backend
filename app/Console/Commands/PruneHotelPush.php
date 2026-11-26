<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PushDeliveryQueue;
use App\Services\PushDeliveryReadiness;
use Illuminate\Console\Command;

final class PruneHotelPush extends Command
{
    protected $signature = 'pms:push-prune';
    protected $description = 'Prune old terminal push deliveries for immutable events';

    public function handle(PushDeliveryQueue $queue, PushDeliveryReadiness $readiness): int
    {
        if (! config('push.enabled')) {
            return self::SUCCESS;
        }
        if ($readiness->scopeIssues() !== []) {
            $this->error('Push pruning scope is invalid.');
            return self::FAILURE;
        }
        $removed = 0;
        foreach ($readiness->hotels()->whereExists(function ($query): void {
            $query->selectRaw('1')->from('push_subscriptions')->whereColumn('push_subscriptions.hotel_id', 'hotels.id');
        })->lazyById(100) as $hotel) {
            if ($readiness->hotelAllowed($hotel)) {
                $removed += $queue->prune($hotel->id);
            }
        }
        $this->line('Pruned '.$removed.' terminal push deliveries.');

        return self::SUCCESS;
    }
}

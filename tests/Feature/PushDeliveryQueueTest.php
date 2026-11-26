<?php

use App\Models\Hotel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushDeliveryQueue;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->hotel = Hotel::create(['name' => 'Queue QA']);
    $this->other = Hotel::create(['name' => 'Other queue QA']);
    $user = User::create(['name' => 'Admin', 'email' => 'queue-qa@example.test', 'password' => 'long-passphrase']);
    $device = fn ($hotel) => PushSubscription::create(['hotel_id' => $hotel->id, 'user_id' => $user->id,
        'endpoint_hash' => str_repeat('a', 64), 'endpoint' => 'https://fcm.googleapis.com/send/qa',
        'p256dh' => 'qa', 'auth' => 'qa', 'device_name' => 'Synthetic']);
    $this->device = $device($this->hotel);
    $this->otherDevice = $device($this->other);
    $this->queue = app(PushDeliveryQueue::class);
});

it('deduplicates, prioritizes, and atomically claims deliveries', function (): void {
    $normal = ['id' => 'new_booking:1', 'kind' => 'new_booking'];
    $urgent = ['id' => 'maintenance_urgent:1', 'kind' => 'maintenance_urgent'];
    $this->queue->enqueue($this->device->id, $normal);
    $this->queue->enqueue($this->device->id, $normal);
    $this->queue->enqueue($this->device->id, $urgent);
    $this->queue->enqueue($this->otherDevice->id, $urgent);
    $rows = $this->queue->ready($this->hotel->id);
    expect($rows)->toHaveCount(2);
    expect($rows[0]->kind)->toBe('maintenance_urgent');
    expect($this->queue->claim($rows[0]))->toBeTrue();
    expect($this->queue->claim($rows[0]))->toBeFalse();
    expect($this->queue->finish($rows[0], 'sent'))->toBeTrue();
    expect($this->queue->finish($rows[0], 'pending'))->toBeFalse();
    expect($this->queue->counts($this->hotel->id))->toMatchArray(['sent' => 1, 'pending' => 1]);
});

it('recovers abandoned leases without crossing hotels or exceeding the retry cap', function (): void {
    $alert = ['id' => 'new_booking:1', 'kind' => 'new_booking'];
    $this->queue->enqueue($this->device->id, $alert);
    $this->queue->enqueue($this->otherDevice->id, $alert);
    $row = $this->queue->ready($this->hotel->id)[0];
    $other = $this->queue->ready($this->other->id)[0];
    expect($this->queue->claim($row))->toBeTrue();
    expect($this->queue->claim($other))->toBeTrue();
    $this->travel(6)->minutes();
    $this->queue->recover($this->hotel->id);
    expect($this->queue->finish($row, 'sent'))->toBeFalse();
    expect($this->queue->counts($this->other->id))->toMatchArray(['processing' => 1]);
    DB::table('push_deliveries')->where('id', $row->id)->update(['attempts' => 4]);
    $final = $this->queue->ready($this->hotel->id)[0];
    expect($this->queue->claim($final))->toBeTrue();
    $this->travel(6)->minutes();
    $this->queue->recover($this->hotel->id);
    expect($this->queue->counts($this->hotel->id))->toMatchArray(['failed' => 1]);
    expect($this->queue->ready($this->hotel->id))->toHaveCount(0);
});

it('honors retry delay and expires stale queued work', function (): void {
    $this->queue->enqueue($this->device->id, ['id' => 'new_booking:1', 'kind' => 'new_booking']);
    $row = $this->queue->ready($this->hotel->id)[0];
    expect($this->queue->claim($row))->toBeTrue();
    expect($this->queue->finish($row, 'pending', 'provider_rate_limited', 600))->toBeTrue();
    $this->travel(9)->minutes();
    expect($this->queue->ready($this->hotel->id))->toHaveCount(0);
    $this->travel(2)->minutes();
    expect($this->queue->ready($this->hotel->id))->toHaveCount(1);
    $this->travel(1)->days();
    $this->queue->recover($this->hotel->id);
    expect($this->queue->counts($this->hotel->id))->toMatchArray(['cancelled' => 1]);
});

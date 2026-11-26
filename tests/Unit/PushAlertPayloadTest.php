<?php

use App\Services\PushAlertPayload;

it('builds scoped booking links and stable hotel-specific tags without guest details', function (): void {
    $alert = ['id' => 'checkout_due:12:2026-11-26', 'kind' => 'checkout_due', 'bookingId' => '12', 'reference' => 'PRIVATE'];
    $payload = PushAlertPayload::make(5, $alert);
    expect($payload['url'])->toBe('/reservations?hotel=5&booking=12&deskAction=check-out');
    expect($payload['id'])->not->toBe(PushAlertPayload::make(6, $alert)['id']);
    expect(json_encode($payload))->not->toContain('PRIVATE');
    $alert['kind'] = 'room_assignment';
    expect(PushAlertPayload::make(5, $alert)['url'])->toContain('deskAction=assign');
});

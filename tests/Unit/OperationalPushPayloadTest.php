<?php

use App\Services\PushAlertPayload;

it('builds operational links and keeps room and staff details off the notification body', function (): void {
    $alert = ['id' => 'maintenance_urgent:8', 'kind' => 'maintenance_urgent', 'target' => 'maintenance', 'orderId' => '8', 'reference' => 'Private room'];
    $payload = PushAlertPayload::make(5, $alert);
    expect($payload['url'])->toBe('/maintenance?hotel=5&order=8');
    expect($payload['body'])->not->toContain('Private room');
    expect(PushAlertPayload::make(5, ['id' => 'room_blocked:2', 'kind' => 'room_blocked', 'target' => 'room-calendar', 'room' => '101', 'calendarDate' => '2026-11-26'])['url'])->toBe('/room-calendar?hotel=5&room=101&date=2026-11-26');
    expect(PushAlertPayload::make(5, ['id' => 'approval_requested:9', 'kind' => 'approval_requested', 'target' => 'manager-approvals', 'approvalId' => '3'])['url'])->toBe('/manager-approvals?hotel=5&approval=3');
});

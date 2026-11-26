<?php

use App\Services\PushAlertPayload;

it('links closing alerts to the hotel business date without including balances', function (): void {
    $payload = PushAlertPayload::make(5, ['id' => 'closing_journal_integrity:1:2026-11-26', 'kind' => 'closing_journal_integrity', 'target' => 'night-audit', 'closingDate' => '2026-11-26', 'balance' => 99999]);
    expect($payload['url'])->toBe('/night-audit?hotel=5&date=2026-11-26');
    expect(json_encode($payload))->not->toContain('99999');
});

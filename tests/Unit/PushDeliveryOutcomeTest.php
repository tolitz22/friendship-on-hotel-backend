<?php

use App\Services\PushDeliveryOutcome;

it('classifies provider responses without treating permanent errors as retryable', function (): void {
    expect(PushDeliveryOutcome::classify(201, true))->toBe(['sent', null]);
    expect(PushDeliveryOutcome::classify(410))->toBe(['expired', 'endpoint_expired']);
    expect(PushDeliveryOutcome::classify(404))->toBe(['expired', 'endpoint_expired']);
    expect(PushDeliveryOutcome::classify(429))->toBe(['pending', 'provider_rate_limited']);
    expect(PushDeliveryOutcome::classify(503))->toBe(['pending', 'provider_temporary']);
    expect(PushDeliveryOutcome::classify(null))->toBe(['pending', 'provider_temporary']);
    expect(PushDeliveryOutcome::classify(403))->toBe(['failed', 'provider_authorization']);
    expect(PushDeliveryOutcome::classify(400))->toBe(['failed', 'provider_rejected']);
});

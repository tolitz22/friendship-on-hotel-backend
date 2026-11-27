<?php

use App\Logging\LokiSettings;
use App\Logging\OtlpLogPayload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('exports sanitized OTLP JSON with service resource, severity and nanosecond timestamps', function (): void {
    $url = 'https://otlp.example.test/otlp/v1/logs';
    config(['logging.channels.loki.with' => [
        'enabled' => true, 'protocol' => 'otlp', 'url' => $url, 'username' => '123', 'token' => 'synthetic-token',
    ], 'logging.channels.loki.level' => 'warning']);
    // Use an explicit JSON object response, as required by OTLP.
    Http::fake([$url => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);
    $logger = Log::channel('loki');
    try {
        $logger->info('not exported');
        $logger->warning('pms.logging.smoke', ['count' => 1, 'password' => 'private-password']);
        $logger->error('private-error', ['email' => 'guest@example.test']);
        $handler = $logger->getLogger()->getHandlers()[0];
        $handler->close();
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($url): bool {
            $groups = $request->data()['resourceLogs'];
            $logs = $groups[0]['scopeLogs'][0]['logRecords'];
            expect($groups)->toHaveCount(1)
                ->and($groups[0]['resource']['attributes'][0])->toBe(['key' => 'service.name', 'value' => ['stringValue' => 'friendship-on-hotel-backend']])
                ->and($logs)->toHaveCount(2)
                ->and($logs[0]['severityNumber'])->toBe(13)
                ->and($logs[1]['severityNumber'])->toBe(17)
                ->and($logs[0]['timeUnixNano'])->toMatch('/^[0-9]{19}$/')
                ->and(json_decode($logs[0]['body']['stringValue'], true)['message'])->toBe('pms.logging.smoke')
                ->and($request->body())->not->toContain('private-password', 'guest@example.test', 'private-error', 'synthetic-token');

            return $request->url() === $url && $request->hasHeader('Authorization', 'Basic '.base64_encode('123:synthetic-token'));
        });
        expect($handler->lastDeliveryAccepted())->toBeTrue();
    } finally {
        Log::forgetChannel('loki');
    }
});

it('validates protocol specific HTTPS endpoints without changing native Loki defaults', function (): void {
    expect(LokiSettings::valid('https://logs.example.test/loki/api/v1/push', '123', 'secret'))->toBeTrue()
        ->and(LokiSettings::valid('https://otlp.example.test/otlp/v1/logs', '123', 'secret', 'otlp'))->toBeTrue()
        ->and(LokiSettings::valid('https://otlp.example.test/otlp/v1/logs', '123', 'secret'))->toBeFalse()
        ->and(LokiSettings::valid('https://logs.example.test/loki/api/v1/push', '123', 'secret', 'otlp'))->toBeFalse()
        ->and(LokiSettings::valid('http://otlp.example.test/otlp/v1/logs', '123', 'secret', 'otlp'))->toBeFalse()
        ->and(LokiSettings::valid('https://otlp.example.test/otlp/v1/logs', '123', 'secret', 'unknown'))->toBeFalse();
});

it('detects OTLP rejected records instead of treating HTTP 200 as proof of acceptance', function (): void {
    expect(OtlpLogPayload::fullyAccepted('{}'))->toBeTrue()
        ->and(OtlpLogPayload::fullyAccepted('{"partialSuccess":{"rejectedLogRecords":"0"}}'))->toBeTrue()
        ->and(OtlpLogPayload::fullyAccepted('{"partialSuccess":{"rejectedLogRecords":"1","errorMessage":"private"}}'))->toBeFalse()
        ->and(OtlpLogPayload::fullyAccepted('not json'))->toBeFalse();
});

it('checks remote acknowledgement in the synthetic command', function (): void {
    $url = 'https://otlp.example.test/otlp/v1/logs';
    config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['stderr', 'loki'],
        'logging.channels.loki.with' => ['enabled' => true, 'protocol' => 'otlp', 'url' => $url, 'username' => '123', 'token' => 'test-token'],
        'logging.channels.loki.level' => 'warning']);
    Http::fake([$url => Http::response('{"partialSuccess":{"rejectedLogRecords":"1"}}', 200)]);
    $this->artisan('pms:loki-check', ['--send' => true])
        ->expectsOutput('Export outcome: otlp_unconfirmed_response')
        ->expectsOutput('HTTP status: 200')
        ->expectsOutput('NO-GO: smoke event not acknowledged. Check protocol, endpoint, token permissions, log level and connectivity.')
        ->assertFailed();
    Log::forgetChannel('loki');
});

it('reports safe HTTP failure diagnostics without printing remote response data', function (): void {
    $url = 'https://otlp.example.test/otlp/v1/logs';
    config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['stderr', 'loki'],
        'logging.channels.loki.with' => ['enabled' => true, 'protocol' => 'otlp', 'url' => $url, 'username' => '123', 'token' => 'test-token'],
        'logging.channels.loki.level' => 'warning']);
    Http::fake([$url => Http::response('private remote response', 401)]);
    try {
        $this->artisan('pms:loki-check', ['--send' => true])
            ->expectsOutput('Export outcome: authentication_or_scope')
            ->expectsOutput('HTTP status: 401')
            ->doesntExpectOutput('private remote response')
            ->assertFailed();
    } finally {
        Log::forgetChannel('loki');
    }
});

it('accepts an empty HTTP 204 response from the Grafana OTLP gateway', function (): void {
    $url = 'https://otlp.example.test/otlp/v1/logs';
    config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['stderr', 'loki'],
        'logging.channels.loki.with' => ['enabled' => true, 'protocol' => 'otlp', 'url' => $url, 'username' => '123', 'token' => 'test-token'],
        'logging.channels.loki.level' => 'warning']);
    Http::fake([$url => Http::response('', 204)]);
    try {
        $this->artisan('pms:loki-check', ['--send' => true])
            ->expectsOutput('Smoke event acknowledged by remote endpoint. Confirm its presence in Grafana Explore.')
            ->assertSuccessful();
        Http::assertSentCount(1);
        $handler = Log::channel('loki')->getLogger()->getHandlers()[0];
        expect($handler->deliveryDiagnostic())->toBe(['outcome' => 'accepted', 'http_status' => 204]);
    } finally {
        Log::forgetChannel('loki');
    }
});

<?php

use App\Logging\BufferedLokiHandler;
use App\Logging\LokiSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('exports only sanitized bounded batches with low-cardinality labels', function (): void {
    $url = 'https://logs.example.test/loki/api/v1/push';
    config(['logging.channels.loki.with' => [
        'enabled' => true, 'url' => $url, 'username' => '123', 'token' => 'test-secret',
    ], 'logging.channels.loki.level' => 'warning']);
    Http::fake([$url => Http::response('', 204)]);
    $logger = Log::channel('loki');
    try {
        $logger->info('ignored');
        for ($i = 0; $i < 42; $i++) {
            $logger->warning('private-message', ['password' => 'secret-password', 'hotel_id' => 2]);
        }
        Http::assertNothingSent();
        $handler = $logger->getLogger()->getHandlers()[0];
        expect($handler)->toBeInstanceOf(BufferedLokiHandler::class);
        $handler->close();
        $handler->close();
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($url): bool {
            $streams = $request->data()['streams'];
            $line = json_decode($streams[0]['values'][0][1], true, 512, JSON_THROW_ON_ERROR);
            expect($streams)->toHaveCount(1)
                ->and($streams[0]['stream'])->toBe(['service' => 'friendship-on-hotel-backend', 'environment' => 'testing', 'level' => 'warning'])
                ->and($streams[0]['values'])->toHaveCount(40)
                ->and($streams[0]['values'][0][0])->toMatch('/^[0-9]{19}$/')
                ->and($line['message'])->toBe('pms.application.log')
                ->and($line['context']['hotel_id'])->toBe(2)
                ->and($request->body())->not->toContain('private-message', 'secret-password', 'test-secret');

            return $request->url() === $url && $request->hasHeader('Authorization', 'Basic '.base64_encode('123:test-secret'));
        });
    } finally {
        Log::forgetChannel('loki');
    }
});

it('does not transmit logs when disabled or misconfigured', function (): void {
    Http::fake();
    config(['logging.channels.loki.with.enabled' => false]);
    $logger = Log::channel('loki');
    $logger->error('test');
    $logger->getLogger()->getHandlers()[0]->close();
    Http::assertNothingSent();
    Log::forgetChannel('loki');
    expect(LokiSettings::valid('http://logs.example.test/loki/api/v1/push', '123', 'secret'))->toBeFalse()
        ->and(LokiSettings::valid('https://logs.example.test/loki/api/v1/push?token=secret', '123', 'secret'))->toBeFalse();
});

it('keeps failed exports nonfatal and avoids immediately retrying', function (): void {
    $url = 'https://logs.example.test/loki/api/v1/push';
    config(['logging.channels.loki.with' => ['enabled' => true, 'url' => $url, 'username' => '123', 'token' => 'test-secret']]);
    Http::fake([$url => Http::response('private failure', 503)]);
    $logger = Log::channel('loki');
    $handler = $logger->getLogger()->getHandlers()[0];
    $logger->error('first');
    $handler->close();
    $logger->error('second');
    $handler->close();
    Http::assertSentCount(1);
    Log::forgetChannel('loki');
});

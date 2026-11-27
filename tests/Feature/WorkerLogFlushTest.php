<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config([
        'logging.default' => 'stack',
        'logging.service_role' => 'worker',
        'logging.channels.stack.channels' => ['stderr', 'loki'],
        'logging.channels.loki.level' => 'warning',
        'logging.channels.loki.with' => [
            'enabled' => true,
            'protocol' => 'otlp',
            'url' => 'https://otlp.example.test/otlp/v1/logs',
            'username' => '123',
            'token' => 'synthetic-token',
        ],
    ]);
});

afterEach(function (): void {
    Log::forgetChannel('stack');
    Log::forgetChannel('loki');
    Log::forgetChannel('stderr');
});

it('flushes successive job attempts and late exception logs before worker shutdown without duplicates', function (): void {
    Http::fake(['https://otlp.example.test/otlp/v1/logs' => Http::response('', 204)]);
    $job = Mockery::mock(Job::class);

    Log::warning('pms.logging.smoke', ['count' => 1]);
    Http::assertNothingSent();
    event(new JobAttempted('database', $job, false));
    Http::assertSentCount(1);

    Log::channel('loki')->error('pms.logging.smoke', ['count' => 2]);
    event(new JobAttempted('database', $job, true));
    Http::assertSentCount(2);

    Log::error('pms.logging.smoke', ['count' => 3]);
    event(new Looping('database', 'default'));
    Http::assertSentCount(3);
    event(new Looping('database', 'default'));
    Http::assertSentCount(3);

    Log::warning('pms.logging.smoke', ['count' => 4]);
    event(new WorkerStopping());
    Http::assertSentCount(4);
    event(new WorkerStopping());
    Http::assertSentCount(4);

    foreach (Http::recorded() as $index => [$request, $response]) {
        $logs = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'];
        expect($logs)->toHaveCount(1);
        $body = json_decode($logs[0]['body']['stringValue'], true, 512, JSON_THROW_ON_ERROR);
        expect($body['context']['count'])->toBe($index + 1)
            ->and($body['extra']['service_role'])->toBe('worker');
    }
});

it('does not resolve logging channels or send requests on idle worker events', function (): void {
    Http::fake();
    Log::forgetChannel('stack');
    Log::forgetChannel('loki');
    event(new Looping('database', 'default'));
    event(new WorkerStopping());
    expect(Log::getChannels())->not->toHaveKey('loki');
    Http::assertNothingSent();
});

it('keeps job lifecycle nonfatal and respects cooldown when the gateway rejects a batch', function (): void {
    Http::fake(['https://otlp.example.test/otlp/v1/logs' => Http::response('private response', 503)]);
    $job = Mockery::mock(Job::class);
    Log::warning('pms.logging.smoke', ['count' => 1]);
    event(new JobAttempted('database', $job, false));
    Http::assertSentCount(1);
    Log::error('pms.logging.smoke', ['count' => 2]);
    event(new JobAttempted('database', $job, true));
    event(new Looping('database', 'default'));
    event(new WorkerStopping());
    Http::assertSentCount(1);
});

it('leaves remote logging disabled at worker lifecycle boundaries', function (): void {
    config(['logging.channels.loki.with.enabled' => false]);
    Http::fake();
    Log::warning('pms.logging.smoke', ['count' => 1]);
    event(new JobAttempted('database', Mockery::mock(Job::class)));
    event(new WorkerStopping());
    Http::assertNothingSent();
});

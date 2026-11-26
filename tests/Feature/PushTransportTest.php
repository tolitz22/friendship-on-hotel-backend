<?php

declare(strict_types=1);

use App\Services\PushTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

beforeEach(function (): void {
    $keys = VAPID::createVapidKeys();
    config(['push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey'], 'push.subject' => 'mailto:qa@example.test', 'push.scope' => 'all']);
});

it('initializes the actual installed Web Push transport and passes read-only preflight', function (): void {
    expect(app(PushTransport::class)->make())->toBeInstanceOf(WebPush::class);
    config(['push.enabled' => false]);
    $this->artisan('pms:push-check')->assertExitCode(0);
});

it('sends an encrypted synthetic payload through a PSR-18 mock and preserves provider status and retry headers', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(201), new Response(429, ['Retry-After' => '120'])]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $sender = app(PushTransport::class)->make($client);
    $deviceKeys = VAPID::createVapidKeys();
    $subscription = Subscription::create([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/synthetic-test-only',
        'publicKey' => $deviceKeys['publicKey'],
        'authToken' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        'contentEncoding' => 'aes128gcm',
    ]);
    $first = $sender->sendOneNotification($subscription, 'Synthetic QA alert', ['TTL' => 300, 'topic' => 'test-alert']);
    $second = $sender->sendOneNotification($subscription, 'Synthetic QA alert', ['TTL' => 300, 'topic' => 'test-alert']);

    expect($first->isSuccess())->toBeTrue();
    expect($first->getResponse()->getStatusCode())->toBe(201);
    expect($second->isSuccess())->toBeFalse();
    expect($second->getResponse()->getStatusCode())->toBe(429);
    expect($second->getResponse()->getHeaderLine('Retry-After'))->toBe('120');
    expect($history)->toHaveCount(2);
    expect($history[0]['request']->getHeaderLine('Content-Encoding'))->toBe('aes128gcm');
    expect((string) $history[0]['request']->getBody())->not->toContain('Synthetic QA alert');
});

it('does not turn an optional runtime performance notice into a transport failure', function (): void {
    $transport = app(PushTransport::class);
    expect($transport->runtimeIssues())->toBe([]);
    set_error_handler(static function (int $severity, string $message): bool {
        if ($severity === E_USER_NOTICE || $severity === E_USER_WARNING) {
            throw new ErrorException('Unexpected PHP runtime notice', 0, $severity);
        }

        return false;
    });
    try {
        expect($transport->make())->toBeInstanceOf(WebPush::class);
    } finally {
        restore_error_handler();
    }
});

it('reports only a safe exception type rather than private exception contents', function (): void {
    $transport = app(PushTransport::class);
    expect($transport->diagnostic(new RuntimeException('SECRET-TEST-VAPID-KEY')))
        ->toBe('transport_exception_type:RuntimeException');
});

<?php

use App\Logging\SafeStructuredLogProcessor;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

it('keeps reviewed event names and numeric metadata only', function (): void {
    $record = new LogRecord(new DateTimeImmutable(), 'production', Level::Info, 'pms.logging.smoke', [
        'hotel_id' => '2', 'count' => 1, 'user_id' => 'not-an-id',
        'password' => 'secret-password', 'guest' => ['email' => 'guest@example.test'],
        'authorization' => 'Bearer secret-token', 'request' => ['body' => 'private'],
    ], ['cookie' => 'secret-cookie']);

    $safe = (new SafeStructuredLogProcessor('production'))($record);

    expect($safe->message)->toBe('pms.logging.smoke')
        ->and($safe->context)->toBe(['hotel_id' => 2, 'count' => 1])
        ->and($safe->extra)->toBe(['service' => 'friendship-on-hotel-backend', 'environment' => 'production', 'schema_version' => 1]);
});

it('removes free-form text and exception details but keeps safe diagnostics', function (): void {
    $exception = new RuntimeException('SQL binding and guest@example.test');
    $record = new LogRecord(new DateTimeImmutable(), 'production', Level::Error, 'Bearer secret-token', [
        'exception' => $exception, 'payment_id' => 42,
    ]);

    $safe = (new SafeStructuredLogProcessor('production'))($record);

    expect($safe->message)->toBe('pms.application.exception')
        ->and($safe->context['exception_type'])->toBe(RuntimeException::class)
        ->and($safe->context['payment_id'])->toBe(42)
        ->and($safe->context['exception_line'])->toBe($exception->getLine())
        ->and($safe->context)->not->toHaveKey('exception')
        ->and($safe->level)->toBe(Level::Error);
});

it('redacts unreviewed free-form messages even without exceptions', function (): void {
    $record = new LogRecord(new DateTimeImmutable(), 'production', Level::Warning, 'Guest guest@example.test');
    expect((new SafeStructuredLogProcessor('production'))($record)->message)->toBe('pms.application.log');
});

it('writes one sanitized JSON record per line with the actual handler', function (): void {
    $stream = fopen('php://memory', 'w+');
    $handler = new StreamHandler($stream);
    $logger = new Logger('production', [$handler]);
    // Exercise the same processor and formatter as the Laravel channel.
    $handler->pushProcessor(new SafeStructuredLogProcessor('production'));
    $handler->setFormatter(new \Monolog\Formatter\JsonFormatter(\Monolog\Formatter\JsonFormatter::BATCH_MODE_JSON, true, false, false));
    $logger->info('pms.logging.smoke', ['count' => 1, 'token' => 'secret-token']);
    $logger->error("private\nmessage", ['exception' => new RuntimeException('secret-exception')]);

    rewind($stream);
    $lines = explode("\n", trim(stream_get_contents($stream)));
    expect($lines)->toHaveCount(2);
    $first = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
    $second = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
    expect($first['message'])->toBe('pms.logging.smoke')
        ->and($first['level_name'])->toBe('INFO')
        ->and($first['context']['count'])->toBe(1)
        ->and($second['message'])->toBe('pms.application.exception')
        ->and(implode('', $lines))->not->toContain('secret-token', 'secret-exception', 'private');
    $handler->close();
});

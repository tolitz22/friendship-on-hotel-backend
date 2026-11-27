<?php

use App\Logging\ConfigureStructuredLogging;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('correlates API responses, exceptions and safe completion logs without leaking input', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'pms-request-');
    try {
        config([
            'app.debug' => false,
            'logging.default' => 'request_test',
            'logging.channels.request_test' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'info',
                'tap' => [ConfigureStructuredLogging::class],
            ],
        ]);
        Route::get('api/log-test/{guest}', function () {
            Log::info('internal private event', ['token' => 'private-token']);

            return response()->json(['ok' => true]);
        });
        Route::get('api/log-failure', function () {
            throw new RuntimeException('private exception guest@example.test');
        });
        Route::get('api/log-denied', fn () => abort(403));

        $spoof = '00000000-0000-4000-8000-000000000001';
        $ok = $this->getJson('/api/log-test/private-guest?token=private-query', ['X-Request-ID' => $spoof])->assertOk();
        $okId = $ok->headers->get('X-Request-ID');
        expect($okId)->toMatch('/^[0-9a-f-]{36}$/')->not->toBe($spoof);

        $failed = $this->getJson('/api/log-failure')->assertStatus(500);
        $failedId = $failed->headers->get('X-Request-ID');
        expect($failedId)->toMatch('/^[0-9a-f-]{36}$/')->not->toBe($okId);

        $denied = $this->getJson('/api/log-denied')->assertForbidden();
        $missing = $this->getJson('/api/private-missing')->assertNotFound();
        expect($denied->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($missing->headers->get('X-Request-ID'))->not->toBeNull();

        Log::info('outside request');
        Log::forgetChannel('request_test');
        $raw = file_get_contents($path);
        $records = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $completed = array_values(array_filter($records, fn ($record) => $record['message'] === 'pms.api.request.completed'));
        expect($completed)->toHaveCount(4)
            ->and(array_column(array_column($completed, 'context'), 'status_code'))->toBe([200, 500, 403, 404])
            ->and($completed[0]['context']['request_id'])->toBe($okId)
            ->and($completed[0]['context']['route_template'])->toBe('api/log-test/{guest}')
            ->and($completed[0]['context']['http_method'])->toBe('GET')
            ->and($completed[0]['context']['duration_ms'])->toBeInt()
            ->and($completed[1]['context']['request_id'])->toBe($failedId)
            ->and($completed[1]['level_name'])->toBe('ERROR')
            ->and($completed[3]['context']['route_template'])->toBe('unmatched');

        $exception = array_values(array_filter($records, fn ($record) => $record['message'] === 'pms.application.exception'));
        expect($exception)->toHaveCount(1)
            ->and($exception[0]['context']['request_id'])->toBe($failedId)
            ->and($records[0]['context']['request_id'])->toBe($okId)
            ->and(end($records)['context'])->not->toHaveKey('request_id')
            ->and($raw)->not->toContain('private-guest', 'private-query', 'private-token', 'guest@example.test', 'private-missing', $spoof);
    } finally {
        Log::forgetChannel('request_test');
        @unlink($path);
    }
});

it('does not track health checks or browser preflight requests', function (): void {
    Log::spy();
    $this->get('/up')->assertOk()->assertHeaderMissing('X-Request-ID');
    $this->call('OPTIONS', '/api/log-test', [], [], [], [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ])->assertNoContent()->assertHeaderMissing('X-Request-ID');
    Log::shouldNotHaveReceived('log');
});

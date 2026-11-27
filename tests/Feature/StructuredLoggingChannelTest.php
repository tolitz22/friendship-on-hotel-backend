<?php

use App\Logging\ConfigureStructuredLogging;
use Illuminate\Support\Facades\Log;

it('configures a real Laravel channel and emits the smoke command event', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'pms-log-');
    try {
        config([
            'logging.default' => 'structured_test',
            'logging.channels.structured_test' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'info',
                'tap' => [ConfigureStructuredLogging::class],
            ],
        ]);
        $this->artisan('pms:logging-check')->assertSuccessful();
        Log::error('guest@example.test', ['password' => 'secret-password', 'hotel_id' => 2]);
        Log::forgetChannel('structured_test');

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toHaveCount(2);
        $smoke = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $error = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        expect($smoke['message'])->toBe('pms.logging.smoke')
            ->and($smoke['extra']['service'])->toBe('friendship-on-hotel-backend')
            ->and($error['message'])->toBe('pms.application.log')
            ->and($error['context']['hotel_id'])->toBe(2)
            ->and(implode('', $lines))->not->toContain('guest@example.test', 'secret-password');
    } finally {
        Log::forgetChannel('structured_test');
        @unlink($path);
    }
});

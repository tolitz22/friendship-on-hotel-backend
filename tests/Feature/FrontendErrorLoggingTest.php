<?php

use App\Logging\ConfigureStructuredLogging;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

it('accepts authenticated bounded reports and excludes arbitrary personal data', function (): void {
    $url = '/api/v1/telemetry/frontend-errors';
    $input = ['kind' => 'api_server_error', 'screen' => 'billing', 'status_code' => 503,
        'related_request_id' => '7cdb572a-df2d-45ee-811b-273e998e8f14',
        'message' => 'guest@example.test', 'stack' => 'private-stack', 'user_id' => 999, 'hotel_id' => 999];
    $this->postJson($url, $input)->assertUnauthorized();
    $user = User::create(['name' => 'Telemetry Test', 'email' => 'telemetry@example.test', 'password' => 'test-only-password']);
    Sanctum::actingAs($user, ['hotel:read']);
    $path = tempnam(sys_get_temp_dir(), 'pms-frontend-error-');
    try {
        config(['logging.default' => 'frontend_test', 'logging.channels.frontend_test' => [
            'driver' => 'single', 'path' => $path, 'level' => 'info', 'tap' => [ConfigureStructuredLogging::class],
        ]]);
        $response = $this->postJson($url, $input)->assertNoContent();
        $this->postJson($url, ['kind' => 'anything', 'screen' => 'billing'])->assertUnprocessable();
        $this->postJson($url, ['kind' => 'react_render', 'screen' => 'guest@example.test'])->assertUnprocessable();
        $this->postJson($url, ['kind' => 'react_render', 'screen' => 'billing', 'stack' => str_repeat('x', 3000)])->assertStatus(413);
        Log::forgetChannel('frontend_test');
        $raw = file_get_contents($path);
        $records = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $events = array_values(array_filter($records, fn ($record) => $record['message'] === 'pms.frontend.error_reported'));
        expect($events)->toHaveCount(1)
            ->and($events[0]['context']['user_id'])->toBe((int) $user->id)
            ->and($events[0]['context']['screen'])->toBe('billing')
            ->and($events[0]['context']['kind'])->toBe('api_server_error')
            ->and($events[0]['context']['status_code'])->toBe(503)
            ->and($events[0]['context']['related_request_id'])->toBe($input['related_request_id'])
            ->and($events[0]['context']['request_id'])->toBe($response->headers->get('X-Request-ID'))
            ->and($events[0]['context'])->not->toHaveKey('hotel_id')
            ->and($raw)->not->toContain('guest@example.test', 'private-stack');
    } finally {
        Log::forgetChannel('frontend_test');
        @unlink($path);
    }
});

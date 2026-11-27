<?php

use App\Logging\ConfigureStructuredLogging;
use App\Logging\BufferedLokiHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NullHandler;

$deployed = in_array(env('APP_ENV', 'production'), ['production', 'staging'], true);

return [
    'default' => env('LOG_CHANNEL', $deployed ? 'stderr' : 'stack'),
    'deprecations' => ['channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'), 'trace' => env('LOG_DEPRECATIONS_TRACE', false)],
    'channels' => [
        'stack' => ['driver' => 'stack', 'channels' => explode(',', (string) env('LOG_STACK', $deployed ? 'stderr' : 'single')), 'ignore_exceptions' => false],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', $deployed ? 'info' : 'debug'),
            'tap' => $deployed ? [ConfigureStructuredLogging::class] : [],
        ],
        // Opt-in only; dedicated filenames prevent rotation touching legacy logs.
        'pms_daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/pms-technical.log'),
            'level' => env('LOG_LEVEL', $deployed ? 'info' : 'debug'),
            'days' => max(1, min(90, (int) env('PMS_LOG_FILE_DAYS', 14))),
            'permission' => 0600,
            'locking' => true,
            'tap' => [ConfigureStructuredLogging::class],
        ],
        'stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'level' => env('LOG_LEVEL', $deployed ? 'info' : 'debug'),
            'tap' => [ConfigureStructuredLogging::class],
        ],
        'loki' => [
            'driver' => 'monolog',
            'handler' => BufferedLokiHandler::class,
            'level' => env('PMS_LOKI_LEVEL', 'warning'),
            'with' => [
                'enabled' => (bool) env('PMS_LOKI_ENABLED', false),
                'url' => (string) env('PMS_LOKI_URL', ''),
                'username' => (string) env('PMS_LOKI_USERNAME', ''),
                'token' => (string) env('PMS_LOKI_TOKEN', ''),
            ],
            'tap' => [ConfigureStructuredLogging::class],
        ],
        'null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
    ],
];

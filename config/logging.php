<?php

use App\Logging\ConfigureStructuredLogging;
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
        'stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'level' => env('LOG_LEVEL', $deployed ? 'info' : 'debug'),
            'tap' => [ConfigureStructuredLogging::class],
        ],
        'null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
    ],
];

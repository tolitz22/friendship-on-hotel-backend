<?php

use App\Http\Middleware\EnsureHotelMember;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'hotel.member' => EnsureHotelMember::class,
            'active.user' => EnsureActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel's default JSON exception responses apply to API requests.
    })
    ->create();

<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment('Friendship On Hotel PMS');
})->purpose('Show the application name');

// A shared-database heartbeat runs even if push delivery is disabled.
\Illuminate\Support\Facades\Schedule::call(static function (): void {
    app(\App\Logging\PlatformHeartbeat::class)->record('scheduler', 'observed');
})->name('pms-platform-heartbeat')->everyMinute();

\Illuminate\Support\Facades\Schedule::command('pms:push-deliver')->everyMinute()->withoutOverlapping(10);

\Illuminate\Support\Facades\Schedule::command('pms:push-prune')->dailyAt('03:20')->withoutOverlapping(10);

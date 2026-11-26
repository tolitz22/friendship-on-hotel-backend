<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment('Friendship On Hotel PMS');
})->purpose('Show the application name');

\Illuminate\Support\Facades\Schedule::command('pms:push-deliver')->everyMinute()->withoutOverlapping(10);

\Illuminate\Support\Facades\Schedule::command('pms:push-prune')->dailyAt('03:20')->withoutOverlapping(10);

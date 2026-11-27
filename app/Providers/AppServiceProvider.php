<?php

namespace App\Providers;

use App\Logging\WorkerLogFlush;
use App\Logging\PlatformHeartbeat;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Domain\Keycards\KeycardProvider::class, \App\Domain\Keycards\MockKeycardProvider::class);
        $this->app->singleton(PlatformHeartbeat::class);
    }

    public function boot(): void
    {
        // Flush after each queue attempt and before polling again (including
        // exceptions reported after an attempt), plus graceful worker exit.
        Event::listen([JobAttempted::class, Looping::class, WorkerStopping::class], static function (): void {
            WorkerLogFlush::flush();
        });
        Event::listen([Looping::class, JobAttempted::class], static function (): void {
            app(PlatformHeartbeat::class)->queueTick();
        });
    }
}

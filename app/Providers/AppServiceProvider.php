<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Domain\Keycards\KeycardProvider::class, \App\Domain\Keycards\MockKeycardProvider::class);
        //
    }

    public function boot(): void
    {
        //
    }
}

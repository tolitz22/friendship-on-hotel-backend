<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);
});

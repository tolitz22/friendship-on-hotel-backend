<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HotelController;
use App\Http\Controllers\Api\RoomCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/hotels', [HotelController::class, 'index']);

        Route::prefix('hotels/{hotel}')->middleware('hotel.member')->group(function (): void {
            Route::get('/', [HotelController::class, 'show']);
            Route::get('/room-types', [RoomCatalogController::class, 'roomTypes']);
            Route::get('/rooms', [RoomCatalogController::class, 'rooms']);

            Route::middleware('hotel.admin')->group(function (): void {
                Route::patch('/', [HotelController::class, 'update']);
                Route::post('/room-types', [RoomCatalogController::class, 'createRoomType']);
                Route::post('/rooms', [RoomCatalogController::class, 'createRoom']);
            });
        });
    });
});

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogEditController;
use App\Http\Controllers\Api\GuestController;
use App\Http\Controllers\Api\PropertyCatalogSummaryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HotelController;
use App\Http\Controllers\Api\RoomCatalogController;
use App\Http\Controllers\Api\ReservationRecordController;
use App\Http\Controllers\Api\ReservationDemandReportController;
use App\Http\Controllers\Api\ReservationPolicyDraftController;
use App\Http\Controllers\Api\ReservationPolicyReadinessController;
use App\Http\Controllers\Api\RoomConflictDiagnosticsController;
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
            Route::get('/catalog-summary', PropertyCatalogSummaryController::class);
            Route::get('/reservation-records', [ReservationRecordController::class, 'index']);
            Route::get('/reservation-demand', ReservationDemandReportController::class);
            Route::get('/reservation-policy-draft', [ReservationPolicyDraftController::class, 'show']);
            Route::get('/reservation-policy-readiness', ReservationPolicyReadinessController::class);
            Route::get('/guests', [GuestController::class, 'index']);
            Route::get('/guests/{guest}', [GuestController::class, 'show'])->whereNumber('guest');
            Route::get('/rooms/{room}/reservation-overlaps', RoomConflictDiagnosticsController::class);

            Route::middleware('hotel.admin')->group(function (): void {
                Route::patch('/', [HotelController::class, 'update']);
                Route::patch('/reservation-policy-draft', [ReservationPolicyDraftController::class, 'update']);
                Route::get('/reservation-policy-draft/history', [ReservationPolicyDraftController::class, 'history']);
                Route::post('/room-types', [RoomCatalogController::class, 'createRoomType']);
                Route::post('/rooms', [RoomCatalogController::class, 'createRoom']);
                Route::patch('/room-types/{type}', [CatalogEditController::class, 'updateType'])->whereNumber('type');
                Route::patch('/rooms/{room}', [CatalogEditController::class, 'updateRoom'])->whereNumber('room');
                Route::get('/catalog-audit', [CatalogEditController::class, 'audit']);
                Route::post('/guests', [GuestController::class, 'store']);
                Route::patch('/guests/{guest}', [GuestController::class, 'update'])->whereNumber('guest');
            });
        });
    });
});

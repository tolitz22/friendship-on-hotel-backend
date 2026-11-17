<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaffPasswordController;
use App\Http\Controllers\Api\SpaSessionController;
use App\Http\Controllers\Api\CatalogEditController;
use App\Http\Controllers\Api\GuestController;
use App\Http\Controllers\Api\HousekeepingTaskController;
use App\Http\Controllers\Api\MaintenanceWorkOrderController;
use App\Http\Controllers\Api\PropertyCatalogSummaryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HotelController;
use App\Http\Controllers\Api\HotelStaffController;
use App\Http\Controllers\Api\StaffInvitationController;
use App\Http\Controllers\Api\RoomCatalogController;
use App\Http\Controllers\Api\ReservationRecordController;
use App\Http\Controllers\Api\ReservationBookingController;
use App\Http\Controllers\Api\RoomInventoryController;
use App\Http\Controllers\Api\ReservationScenarioController;
use App\Http\Controllers\Api\ReservationDemandReportController;
use App\Http\Controllers\Api\ReservationPolicyDraftController;
use App\Http\Controllers\Api\ReservationPolicyReadinessController;
use App\Http\Controllers\Api\RoomConflictDiagnosticsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/spa-login', [SpaSessionController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/forgot-password', [StaffPasswordController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [StaffPasswordController::class, 'reset'])->middleware('throttle:5,1');
    Route::post('/auth/accept-invitation', [StaffInvitationController::class, 'accept'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/spa-logout', [SpaSessionController::class, 'logout']);
        Route::post('/auth/change-password', [StaffPasswordController::class, 'change'])->middleware('throttle:5,1');
        Route::get('/hotels', [HotelController::class, 'index']);

        Route::prefix('hotels/{hotel}')->middleware('hotel.member')->group(function (): void {
            Route::get('/', [HotelController::class, 'show']);
            Route::get('/room-types', [RoomCatalogController::class, 'roomTypes']);
            Route::get('/rooms', [RoomCatalogController::class, 'rooms']);
            Route::get('/catalog-summary', PropertyCatalogSummaryController::class);
            Route::get('/reservation-records', [ReservationRecordController::class, 'index']);
            Route::get('/reservation-demand', ReservationDemandReportController::class);
            Route::get('/room-type-availability', [RoomInventoryController::class, 'availability']);
            Route::get('/reservation-simulation', ReservationScenarioController::class);
            Route::get('/reservation-policy-draft', [ReservationPolicyDraftController::class, 'show']);
            Route::get('/reservation-policy-readiness', ReservationPolicyReadinessController::class);
            Route::patch('/housekeeping-tasks/{task}', [HousekeepingTaskController::class, 'update'])->whereNumber('task');
            Route::patch('/maintenance-work-orders/{order}', [MaintenanceWorkOrderController::class, 'update'])->whereNumber('order');
            Route::get('/housekeeping-tasks', [HousekeepingTaskController::class, 'index']);
            Route::get('/housekeeping-tasks/{task}', [HousekeepingTaskController::class, 'show'])->whereNumber('task');
            Route::get('/housekeeping-tasks/{task}/history', [HousekeepingTaskController::class, 'history'])->whereNumber('task');
            Route::get('/maintenance-work-orders', [MaintenanceWorkOrderController::class, 'index']);
            Route::get('/maintenance-work-orders/{order}', [MaintenanceWorkOrderController::class, 'show'])->whereNumber('order');
            Route::get('/maintenance-work-orders/{order}/history', [MaintenanceWorkOrderController::class, 'history'])->whereNumber('order');
            Route::get('/guests', [GuestController::class, 'index']);
            Route::get('/guests/{guest}', [GuestController::class, 'show'])->whereNumber('guest');
            Route::get('/rooms/{room}/reservation-overlaps', RoomConflictDiagnosticsController::class);

            Route::middleware('hotel.admin')->group(function (): void {
                Route::patch('/', [HotelController::class, 'update']);
                Route::get('/staff', [HotelStaffController::class, 'index']);
                Route::get('/staff/invitations', [StaffInvitationController::class, 'index']);
                Route::post('/staff/invitations', [StaffInvitationController::class, 'store'])->middleware('throttle:10,1');
                Route::patch('/staff/invitations/{invitation}/revoke', [StaffInvitationController::class, 'revoke'])->whereNumber('invitation');
                Route::get('/staff/history', [HotelStaffController::class, 'history']);
                Route::post('/staff', [HotelStaffController::class, 'attach']);
                Route::patch('/staff/{userId}', [HotelStaffController::class, 'update'])->whereNumber('userId');
                Route::get('/inventory-blocks', [RoomInventoryController::class, 'blocks']);
                Route::post('/inventory-blocks', [RoomInventoryController::class, 'block']);
                Route::post('/inventory-blocks/{block}/release', [RoomInventoryController::class, 'release'])->whereNumber('block');
                Route::patch('/bookings/{booking}/assign-room', [RoomInventoryController::class, 'assign'])->whereNumber('booking');
                Route::post('/bookings', [ReservationBookingController::class, 'store']);
                Route::post('/bookings/{booking}/cancel', [ReservationBookingController::class, 'cancel'])->whereNumber('booking');
                Route::post('/bookings/{booking}/check-in', [ReservationBookingController::class, 'checkIn'])->whereNumber('booking');
                Route::post('/bookings/{booking}/check-out', [ReservationBookingController::class, 'checkOut'])->whereNumber('booking');
                Route::patch('/reservation-policy-draft', [ReservationPolicyDraftController::class, 'update']);
                Route::get('/reservation-policy-draft/history', [ReservationPolicyDraftController::class, 'history']);
                Route::post('/room-types', [RoomCatalogController::class, 'createRoomType']);
                Route::post('/rooms', [RoomCatalogController::class, 'createRoom']);
                Route::patch('/room-types/{type}', [CatalogEditController::class, 'updateType'])->whereNumber('type');
                Route::patch('/rooms/{room}', [CatalogEditController::class, 'updateRoom'])->whereNumber('room');
                Route::get('/catalog-audit', [CatalogEditController::class, 'audit']);
                Route::post('/housekeeping-tasks', [HousekeepingTaskController::class, 'store']);
                Route::post('/maintenance-work-orders', [MaintenanceWorkOrderController::class, 'store']);
                Route::post('/guests', [GuestController::class, 'store']);
                Route::patch('/guests/{guest}', [GuestController::class, 'update'])->whereNumber('guest');
            });
        });
    });
});

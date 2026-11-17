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
use App\Http\Controllers\Api\RoomCalendarController;
use App\Http\Controllers\Api\ReservationRecordController;
use App\Http\Controllers\Api\ReservationHistoryController;
use App\Http\Controllers\Api\ReservationTurnoverController;
use App\Http\Controllers\Api\ReservationBookingController;
use App\Http\Controllers\Api\FrontDeskReservationController;
use App\Http\Controllers\Api\ReservationFinanceController;
use App\Http\Controllers\Api\FolioAccountingController;
use App\Http\Controllers\Api\FinancePolicyController;
use App\Http\Controllers\Api\FinanceWebhookController;
use App\Http\Controllers\Api\PayMongoSandboxController;
use App\Http\Controllers\Api\FinanceEventReviewController;
use App\Http\Controllers\Api\RoomInventoryController;
use App\Http\Controllers\Api\RoomReadinessController;
use App\Http\Controllers\Api\ReadinessEnforcementPolicyController;
use App\Http\Controllers\Api\RoomReadinessDashboardController;
use App\Http\Controllers\Api\ReservationScenarioController;
use App\Http\Controllers\Api\ReservationDemandReportController;
use App\Http\Controllers\Api\ReservationPolicyDraftController;
use App\Http\Controllers\Api\ReservationPolicyReadinessController;
use App\Http\Controllers\Api\RoomConflictDiagnosticsController;
use App\Http\Controllers\Api\OperationalExceptionsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::post('/integrations/finance/custom-hmac-v1/events', FinanceWebhookController::class)->middleware('throttle:30,1');
    Route::post('/integrations/paymongo/sandbox/events', [PayMongoSandboxController::class, 'webhook'])->middleware('throttle:30,1');

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
            Route::get('/room-calendar', RoomCalendarController::class);
            Route::get('/catalog-summary', PropertyCatalogSummaryController::class);
            Route::get('/reservation-records', [ReservationRecordController::class, 'index'])->middleware('hotel.permission:reservation.read');
            Route::get('/reservation-records/{booking}', [ReservationRecordController::class, 'show'])->whereNumber('booking')->middleware('hotel.permission:reservation.read');
            Route::get('/bookings/{booking}/history', ReservationHistoryController::class)->whereNumber('booking')->middleware('hotel.permission:reservation.read');
            Route::get('/bookings/{booking}/turnover', ReservationTurnoverController::class)->whereNumber('booking')->middleware('hotel.permission:reservation.read');
            Route::get('/reservation-demand', ReservationDemandReportController::class);
            Route::get('/room-type-availability', [RoomInventoryController::class, 'availability']);
            Route::get('/front-desk/manifest', [FrontDeskReservationController::class, 'manifest'])->middleware('hotel.permission:reservation.read');
            Route::get('/rate-plans', [ReservationFinanceController::class, 'rates']);
            Route::get('/room-quotes', [ReservationFinanceController::class, 'quote']);
            Route::get('/bookings/{booking}/folio', [ReservationFinanceController::class, 'folio'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/bookings/{booking}/folio-ledger', [FolioAccountingController::class, 'show'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/finance/charge-policy-drafts', [FinancePolicyController::class, 'policies']);
            Route::get('/bookings/{booking}/finance-event-reviews', [FinanceEventReviewController::class, 'index'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/bookings/{booking}/charge-preview', [FinancePolicyController::class, 'preview'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/bookings/{booking}/refund-requests', [FinancePolicyController::class, 'refunds'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
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
            Route::get('/guests', [GuestController::class, 'index'])->middleware('hotel.permission:guest.read');
            Route::get('/guests/{guest}', [GuestController::class, 'show'])->whereNumber('guest')->middleware('hotel.permission:guest.read');
            Route::get('/rooms/{room}/reservation-overlaps', RoomConflictDiagnosticsController::class);
            Route::get('/rooms/{room}/readiness', [RoomReadinessController::class, 'show'])->whereNumber('room');
            Route::get('/rooms/{room}/readiness/history', [RoomReadinessController::class, 'history'])->whereNumber('room');
            Route::get('/readiness/dashboard', RoomReadinessDashboardController::class);
            Route::get('/readiness/enforcement-policy', [ReadinessEnforcementPolicyController::class, 'show']);
            Route::get('/readiness/enforcement-policy/history', [ReadinessEnforcementPolicyController::class, 'history']);

            // Phase 50: explicit low-risk operational permissions; all other
            // sensitive mutations remain inside the administrator group.
            Route::get('/inventory-blocks', [RoomInventoryController::class, 'blocks'])
                ->middleware('hotel.permission:booking.assign_room');
            Route::post('/bookings', [ReservationBookingController::class, 'store'])
                ->middleware('hotel.permission:booking.create');
            Route::patch('/bookings/{booking}/assign-room', [RoomInventoryController::class, 'assign'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.assign_room');
            Route::post('/bookings/{booking}/check-in', [ReservationBookingController::class, 'checkIn'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.check_in');
            Route::post('/bookings/{booking}/check-out', [ReservationBookingController::class, 'checkOut'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.check_out');

            Route::middleware('hotel.admin')->group(function (): void {
                Route::patch('/', [HotelController::class, 'update']);
                Route::get('/operational-exceptions', OperationalExceptionsController::class);
                Route::get('/staff', [HotelStaffController::class, 'index']);
                Route::get('/staff/invitations', [StaffInvitationController::class, 'index']);
                Route::post('/staff/invitations', [StaffInvitationController::class, 'store'])->middleware('throttle:10,1');
                Route::patch('/staff/invitations/{invitation}/revoke', [StaffInvitationController::class, 'revoke'])->whereNumber('invitation');
                Route::get('/staff/history', [HotelStaffController::class, 'history']);
                Route::post('/staff', [HotelStaffController::class, 'attach']);
                Route::patch('/staff/{userId}', [HotelStaffController::class, 'update'])->whereNumber('userId');
                Route::post('/inventory-blocks', [RoomInventoryController::class, 'block']);
                Route::post('/inventory-blocks/{block}/release', [RoomInventoryController::class, 'release'])->whereNumber('block');
                Route::post('/rate-plans', [ReservationFinanceController::class, 'createRate']);
                Route::post('/finance/charge-policy-drafts', [FinancePolicyController::class, 'draft']);
                Route::post('/finance/charge-policy-drafts/{policy}/review', [FinancePolicyController::class, 'reviewDraft'])->whereNumber('policy');
                Route::get('/finance/events', [FinanceEventReviewController::class, 'inbox']);
                Route::get('/finance/paymongo-sandbox/events', [PayMongoSandboxController::class, 'index']);
                Route::post('/bookings/{booking}/finance-event-reviews', [FinanceEventReviewController::class, 'match'])->whereNumber('booking');
                Route::post('/bookings/{booking}/refund-requests', [FinancePolicyController::class, 'requestRefund'])->whereNumber('booking');
                Route::post('/bookings/{booking}/refund-requests/{refund}/review', [FinancePolicyController::class, 'reviewRefund'])->whereNumber('booking')->whereNumber('refund');
                Route::post('/bookings/{booking}/cancel-priced-unpaid', [FolioAccountingController::class, 'cancelUnpaid'])->whereNumber('booking');
                Route::post('/bookings/{booking}/folio-adjustments', [FolioAccountingController::class, 'adjust'])->whereNumber('booking');
                Route::post('/bookings/{booking}/payment-records', [FolioAccountingController::class, 'recordPayment'])->whereNumber('booking');
                Route::post('/bookings/{booking}/payment-records/{payment}/review', [FolioAccountingController::class, 'reviewPayment'])->whereNumber('booking')->whereNumber('payment');
                Route::patch('/bookings/{booking}/dates', [FrontDeskReservationController::class, 'amend'])->whereNumber('booking');
                Route::post('/bookings/{booking}/cancel', [ReservationBookingController::class, 'cancel'])->whereNumber('booking');
                Route::patch('/reservation-policy-draft', [ReservationPolicyDraftController::class, 'update']);
                Route::get('/reservation-policy-draft/history', [ReservationPolicyDraftController::class, 'history']);
                Route::post('/room-types', [RoomCatalogController::class, 'createRoomType']);
                Route::post('/rooms', [RoomCatalogController::class, 'createRoom']);
                Route::patch('/room-types/{type}', [CatalogEditController::class, 'updateType'])->whereNumber('type');
                Route::patch('/rooms/{room}', [CatalogEditController::class, 'updateRoom'])->whereNumber('room');
                Route::get('/catalog-audit', [CatalogEditController::class, 'audit']);
                Route::patch('/readiness/enforcement-policy', [ReadinessEnforcementPolicyController::class, 'update']);
                Route::post('/rooms/{room}/readiness/inspections', [RoomReadinessController::class, 'inspect'])->whereNumber('room');
                Route::post('/rooms/{room}/readiness/inspections/{inspection}/review', [RoomReadinessController::class, 'review'])->whereNumber('room')->whereNumber('inspection');
                Route::post('/housekeeping-tasks', [HousekeepingTaskController::class, 'store']);
                Route::post('/maintenance-work-orders', [MaintenanceWorkOrderController::class, 'store']);
                Route::post('/guests', [GuestController::class, 'store']);
                Route::patch('/guests/{guest}', [GuestController::class, 'update'])->whereNumber('guest');
            });
        });
    });
});

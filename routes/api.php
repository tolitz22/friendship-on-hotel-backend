<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaffPasswordController;
use App\Http\Controllers\Api\SpaSessionController;
use App\Http\Controllers\Api\CatalogEditController;
use App\Http\Controllers\Api\GuestController;
use App\Http\Controllers\Api\HousekeepingTaskController;
use App\Http\Controllers\Api\HousekeepingHandoffController;
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
use App\Http\Controllers\Api\GroupReservationController;
use App\Http\Controllers\Api\GroupAllotmentController;
use App\Http\Controllers\Api\GroupFinanceController;
use App\Http\Controllers\Api\FrontDeskNotificationController;
use App\Http\Controllers\Api\FrontDeskReservationController;
use App\Http\Controllers\Api\ReservationFinanceController;
use App\Http\Controllers\Api\FolioAccountingController;
use App\Http\Controllers\Api\FolioSettlementController;
use App\Http\Controllers\Api\FinanceFolioIndexController;
use App\Http\Controllers\Api\FolioRefundDisbursementController;
use App\Http\Controllers\Api\FinancePolicyController;
use App\Http\Controllers\Api\FinanceWebhookController;
use App\Http\Controllers\Api\PayMongoSandboxController;
use App\Http\Controllers\Api\FinanceEventReviewController;
use App\Http\Controllers\Api\RoomInventoryController;
use App\Http\Controllers\Api\RoomReadinessController;
use App\Http\Controllers\Api\ReadinessEnforcementPolicyController;
use App\Http\Controllers\Api\RoomReadinessDashboardController;
use App\Http\Controllers\Api\RoomRackController;
use App\Http\Controllers\Api\RoomInventoryStatusController;
use App\Http\Controllers\Api\NightAuditPreviewController;
use App\Http\Controllers\Api\NightAuditReviewController;
use App\Http\Controllers\Api\NightAuditFinancialReportController;
use App\Http\Controllers\Api\FinancialBusinessDayController;
use App\Http\Controllers\Api\FinancialTaxPolicyController;
use App\Http\Controllers\Api\FolioNightAssessmentController;
use App\Http\Controllers\Api\FolioNightCorrectionController;
use App\Http\Controllers\Api\GuestStayHistoryController;
use App\Http\Controllers\Api\ReservationSpecialRequestController;
use App\Http\Controllers\Api\ReservationScenarioController;
use App\Http\Controllers\Api\ReservationDemandReportController;
use App\Http\Controllers\Api\ReservationPolicyDraftController;
use App\Http\Controllers\Api\ReservationPolicyReadinessController;
use App\Http\Controllers\Api\RoomConflictDiagnosticsController;
use App\Http\Controllers\Api\OperationalExceptionsController;
use App\Http\Controllers\Api\ManagerApprovalController;
use App\Http\Controllers\Api\CashierShiftController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\EmployeeReimbursementController;
use App\Http\Controllers\Api\SupplierBillController;
use App\Http\Controllers\Api\ExpenseCorrectionController;
use App\Http\Controllers\Api\GuestIncidentalChargeController;
use App\Http\Controllers\Api\OccupancyVerificationController;
use App\Http\Controllers\Api\OccupancyInvestigationController;
use App\Http\Controllers\Api\OccupancyCoverageReportController;
use App\Http\Controllers\Api\KeycardAuthorizationController;
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
            Route::prefix('staff-messaging')->group(function (): void {
                Route::post('/conversations/{conversation}/announcements/{message}/acknowledge', [\App\Http\Controllers\Api\StaffMessageController::class, 'acknowledge'])->whereNumber('conversation')->whereNumber('message')->middleware('throttle:60,1');
                Route::get('/records', [\App\Http\Controllers\Api\StaffMessageController::class, 'records'])->middleware('throttle:60,1');
                Route::get('/directory', [\App\Http\Controllers\Api\StaffMessageController::class, 'directory']);
                Route::get('/conversations', [\App\Http\Controllers\Api\StaffMessageController::class, 'index']);
                Route::post('/conversations', [\App\Http\Controllers\Api\StaffMessageController::class, 'create'])->middleware('throttle:20,1');
                Route::get('/conversations/{conversation}', [\App\Http\Controllers\Api\StaffMessageController::class, 'show'])->whereNumber('conversation');
                Route::post('/conversations/{conversation}/messages', [\App\Http\Controllers\Api\StaffMessageController::class, 'send'])->whereNumber('conversation')->middleware('throttle:60,1');
                Route::get('/conversations/{conversation}/attachments/{attachment}', [\App\Http\Controllers\Api\StaffMessageController::class, 'download'])->whereNumber('conversation')->whereNumber('attachment')->middleware('throttle:60,1');
                Route::post('/conversations/{conversation}/read', [\App\Http\Controllers\Api\StaffMessageController::class, 'read'])->whereNumber('conversation')->middleware('throttle:120,1');
            });
            Route::get('/', [HotelController::class, 'show']);
            Route::get('/room-types', [RoomCatalogController::class, 'roomTypes']);
            Route::get('/rooms', [RoomCatalogController::class, 'rooms']);
            Route::get('/room-calendar', RoomCalendarController::class);
            Route::get('/catalog-summary', PropertyCatalogSummaryController::class);
            Route::get('/reservation-records', [ReservationRecordController::class, 'index'])->middleware('hotel.permission:reservation.read');
            Route::get('/reservation-records/{booking}', [ReservationRecordController::class, 'show'])->whereNumber('booking')->middleware('hotel.permission:reservation.read');
            Route::get('/bookings/{booking}/history', ReservationHistoryController::class)->whereNumber('booking')->middleware('hotel.permission:reservation.read');
            Route::get('/bookings/{booking}/special-requests', [ReservationSpecialRequestController::class,'index'])->whereNumber('booking')->middleware('hotel.permission:reservation.read');
            Route::post('/bookings/{booking}/special-requests', [ReservationSpecialRequestController::class,'store'])->whereNumber('booking')->middleware('hotel.permission:booking.special_request');
            Route::get('/bookings/{booking}/turnover', ReservationTurnoverController::class)->whereNumber('booking')->middleware('hotel.permission:reservation.read');
            Route::get('/reservation-demand', ReservationDemandReportController::class);
            Route::get('/room-type-availability', [RoomInventoryController::class, 'availability']);
            Route::get('/reports/booking-forecast', [RoomInventoryController::class, 'forecast'])
                ->middleware('hotel.permission:reservation.read');
            Route::get('/front-desk/notifications', FrontDeskNotificationController::class)
                ->middleware('hotel.permission:reservation.read');
            Route::get('/front-desk/manifest', [FrontDeskReservationController::class, 'manifest'])->middleware('hotel.permission:reservation.read');
            Route::get('/rate-plans', [ReservationFinanceController::class, 'rates']);
            Route::post('/dynamic-room-quote', [\App\Http\Controllers\Api\BookingDynamicQuoteController::class, 'quote'])->middleware('hotel.permission:booking.create')->middleware('throttle:30,1');
            Route::get('/room-quotes', [ReservationFinanceController::class, 'quote']);
            Route::get('/bookings/{booking}/folio', [ReservationFinanceController::class, 'folio'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/bookings/{booking}/folio-ledger', [FolioAccountingController::class, 'show'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::post('/bookings/{booking}/incidental-charges', GuestIncidentalChargeController::class)
                ->whereNumber('booking')->middleware('hotel.permission:cashier.write')
                ->middleware('throttle:20,1');
            Route::get('/bookings/{booking}/payment-safety', [FolioAccountingController::class, 'paymentSafety'])
                ->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/finance/folios', FinanceFolioIndexController::class)->middleware('hotel.permission:finance.read');
            Route::get('/finance/night-audit-reconciliation', NightAuditFinancialReportController::class)
                ->middleware('hotel.permission:finance.read');
            Route::get('/bookings/{booking}/settlement-statement', [FolioSettlementController::class, 'statement'])
                ->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/finance/charge-policy-drafts', [FinancePolicyController::class, 'policies']);
            Route::get('/bookings/{booking}/finance-event-reviews', [FinanceEventReviewController::class, 'index'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/bookings/{booking}/charge-preview', [FinancePolicyController::class, 'preview'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/bookings/{booking}/refund-requests', [FinancePolicyController::class, 'refunds'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
            Route::get('/reservation-simulation', ReservationScenarioController::class);
            Route::get('/reservation-policy-draft', [ReservationPolicyDraftController::class, 'show']);
            Route::get('/reservation-policy-readiness', ReservationPolicyReadinessController::class);
            Route::patch('/housekeeping-tasks/{task}', [HousekeepingTaskController::class, 'update'])->whereNumber('task');
            Route::post('/housekeeping-tasks/{task}/final-inspection', [HousekeepingTaskController::class, 'inspect'])->whereNumber('task')->middleware('throttle:20,1');
            Route::patch('/maintenance-work-orders/{order}', [MaintenanceWorkOrderController::class, 'update'])->whereNumber('order');
            Route::get('/housekeeping/handoff', HousekeepingHandoffController::class)
                ->middleware('hotel.admin');
            Route::get('/housekeeping/my-assignments', [HousekeepingHandoffController::class, 'myAssignments']);
            Route::get('/housekeeping-tasks', [HousekeepingTaskController::class, 'index']);
            Route::get('/housekeeping-tasks/{task}', [HousekeepingTaskController::class, 'show'])->whereNumber('task');
            Route::get('/housekeeping-tasks/{task}/history', [HousekeepingTaskController::class, 'history'])->whereNumber('task');
            Route::get('/maintenance-work-orders', [MaintenanceWorkOrderController::class, 'index']);
            Route::get('/maintenance-work-orders/{order}', [MaintenanceWorkOrderController::class, 'show'])->whereNumber('order');
            Route::get('/maintenance-work-orders/{order}/history', [MaintenanceWorkOrderController::class, 'history'])->whereNumber('order');
            Route::get('/guests', [GuestController::class, 'index'])->middleware('hotel.permission:guest.read');
            Route::get('/guests/{guest}', [GuestController::class, 'show'])->whereNumber('guest')->middleware('hotel.permission:guest.read');
            Route::get('/guests/{guest}/stays', GuestStayHistoryController::class)->whereNumber('guest')->middleware('hotel.permission:guest.read');
            Route::get('/rooms/{room}/reservation-overlaps', RoomConflictDiagnosticsController::class);
            Route::get('/rooms/{room}/readiness', [RoomReadinessController::class, 'show'])->whereNumber('room');
            Route::get('/rooms/{room}/readiness/history', [RoomReadinessController::class, 'history'])->whereNumber('room');
            Route::get('/readiness/dashboard', RoomReadinessDashboardController::class);
            Route::get('/rooms/rack', RoomRackController::class)
                ->middleware('hotel.permission:reservation.read');
            Route::get('/room-inventory-status', RoomInventoryStatusController::class)
                ->middleware('hotel.permission:reservation.read');
            Route::get('/readiness/enforcement-policy', [ReadinessEnforcementPolicyController::class, 'show']);
            Route::get('/readiness/enforcement-policy/history', [ReadinessEnforcementPolicyController::class, 'history']);

            Route::post('/bookings/{booking}/no-show', [ReservationBookingController::class, 'noShow'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.check_in')->middleware('throttle:20,1');

            // Phase 50: explicit low-risk operational permissions; all other
            // sensitive mutations remain inside the administrator group.
            Route::get('/inventory-blocks', [RoomInventoryController::class, 'blocks'])
                ->middleware('hotel.permission:booking.assign_room');
            Route::get('/group-allotments', [GroupAllotmentController::class, 'index'])
                ->middleware('hotel.permission:reservation.read');
            Route::post('/group-allotments', [GroupAllotmentController::class, 'store'])
                ->middleware('hotel.admin')->middleware('throttle:10,1');
            Route::post('/group-allotments/{allotment}/release', [GroupAllotmentController::class, 'release'])
                ->whereNumber('allotment')->middleware('hotel.admin')->middleware('throttle:10,1');
            Route::get('/group-reservations/{group}/financial-summary', GroupFinanceController::class)
                ->whereNumber('group')->middleware('hotel.permission:finance.read');
            Route::post('/group-reservations/{group}/bookings/{booking}/payments/{payment}/allocations',
                [GroupFinanceController::class, 'allocate'])
                ->whereNumber('group')->whereNumber('booking')->whereNumber('payment')
                ->middleware('hotel.admin')->middleware('throttle:10,1');
            Route::get('/group-reservations', [GroupReservationController::class, 'index'])
                ->middleware('hotel.permission:reservation.read');
            Route::get('/group-reservations/{group}', [GroupReservationController::class, 'show'])
                ->whereNumber('group')->middleware('hotel.permission:reservation.read');
            Route::post('/group-reservations', [GroupReservationController::class, 'store'])
                ->middleware('hotel.permission:booking.create')->middleware('throttle:10,1');
            Route::post('/bookings', [ReservationBookingController::class, 'store'])
                ->middleware('hotel.permission:booking.create');
            Route::get('/bookings/{booking}/room-choices', [RoomInventoryController::class, 'assignmentOptions'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.assign_room');
            Route::patch('/bookings/{booking}/assign-room', [RoomInventoryController::class, 'assign'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.assign_room');
            Route::post('/bookings/{booking}/check-in', [ReservationBookingController::class, 'checkIn'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.check_in');
            Route::post('/bookings/{booking}/extend-stay', [FrontDeskReservationController::class, 'extend'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.check_out');
            Route::post('/bookings/{booking}/departure-follow-up', [FrontDeskReservationController::class, 'followUp'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.check_out');
            Route::post('/bookings/{booking}/check-out', [ReservationBookingController::class, 'checkOut'])
                ->whereNumber('booking')->middleware('hotel.permission:booking.check_out');

            // Reception can request narrow exceptions; managers can review and
            // atomically execute them through the original domain services.
            Route::get('/approval-requests', [ManagerApprovalController::class, 'index'])
                ->middleware('hotel.permission:approval.read');
            Route::get('/approval-requests/{approval}/history', [ManagerApprovalController::class, 'history'])
                ->whereNumber('approval')->middleware('hotel.permission:approval.read');
            Route::post('/bookings/{booking}/approval-requests', [ManagerApprovalController::class, 'store'])
                ->whereNumber('booking')->middleware('hotel.permission:approval.request')
                ->middleware('throttle:20,1');

            Route::get('/employee-reimbursements', [EmployeeReimbursementController::class, 'index'])->middleware('hotel.permission:reimbursement.read_own');
            Route::get('/employee-reimbursements/{claim}', [EmployeeReimbursementController::class, 'show'])->whereNumber('claim')->middleware('hotel.permission:reimbursement.read_own');
            Route::post('/employee-reimbursements', [EmployeeReimbursementController::class, 'store'])->middleware('hotel.permission:reimbursement.submit')->middleware('throttle:20,1');
            Route::post('/employee-reimbursements/{claim}/decision', [EmployeeReimbursementController::class, 'decide'])->whereNumber('claim')->middleware('hotel.permission:reimbursement.review')->middleware('throttle:20,1');
            Route::post('/employee-reimbursements/{claim}/payments', [EmployeeReimbursementController::class, 'pay'])->whereNumber('claim')->middleware('hotel.permission:reimbursement.pay')->middleware('throttle:20,1');
            Route::get('/supplier-bills', [SupplierBillController::class, 'index'])->middleware('hotel.permission:supplier.read');
            Route::get('/supplier-bills/aging', [SupplierBillController::class, 'aging'])->middleware('hotel.permission:supplier.read');
            Route::get('/supplier-bills/{bill}', [SupplierBillController::class, 'show'])->whereNumber('bill')->middleware('hotel.permission:supplier.read');
            Route::post('/supplier-bills', [SupplierBillController::class, 'store'])->middleware('hotel.permission:supplier.bill.post')->middleware('throttle:20,1');
            Route::post('/supplier-bills/{bill}/payments', [SupplierBillController::class, 'pay'])->whereNumber('bill')->middleware('hotel.permission:supplier.bill.pay')->middleware('throttle:20,1');
            Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('hotel.permission:supplier.read');
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->middleware('hotel.permission:supplier.read');
            Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('hotel.permission:supplier.manage')->middleware('throttle:20,1');
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->middleware('hotel.permission:supplier.manage')->middleware('throttle:20,1');
            Route::get('/expenses', [ExpenseController::class, 'index'])->middleware('hotel.permission:expense.read');
            Route::post('/expenses', [ExpenseController::class, 'recordDirect'])->middleware('hotel.permission:expense.record_direct')->middleware('throttle:20,1');
            Route::get('/expenses/exceptions', [ExpenseController::class, 'exceptions'])->middleware('hotel.permission:expense.read');
            Route::get('/expenses/policy', [ExpenseController::class, 'policy'])->middleware('hotel.permission:expense.read');
            Route::put('/expenses/policy', [ExpenseController::class, 'updatePolicy'])->middleware('hotel.permission:expense.policy')->middleware('throttle:10,1');
            Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->middleware('hotel.permission:expense.read');
            Route::get('/expenses/{expense}/history', [ExpenseCorrectionController::class, 'history'])->whereNumber('expense')->middleware('hotel.permission:expense.read');
            Route::post('/expenses/{expense}/corrections', [ExpenseCorrectionController::class, 'requestCorrection'])->whereNumber('expense')->middleware('hotel.permission:expense.review')->middleware('throttle:20,1');
            Route::post('/expense-corrections/{correction}/decision', [ExpenseCorrectionController::class, 'decide'])->whereNumber('correction')->middleware('hotel.permission:expense.review')->middleware('throttle:20,1');
            Route::get('/cashier-shifts/{shift}/expenses/{expense}/cash-return-quote', [ExpenseCorrectionController::class, 'returnQuote'])->whereNumber('shift')->whereNumber('expense')->middleware('hotel.permission:cashier.write')->middleware('hotel.permission:expense.record')->middleware('throttle:30,1');
            Route::post('/cashier-shifts/{shift}/expenses/{expense}/cash-returns', [ExpenseCorrectionController::class, 'recordReturn'])->whereNumber('shift')->whereNumber('expense')->middleware('hotel.permission:cashier.write')->middleware('hotel.permission:expense.record')->middleware('throttle:20,1');
            Route::post('/expense-cash-returns/{cashReturn}/review', [ExpenseCorrectionController::class, 'reviewReturn'])->whereNumber('cashReturn')->middleware('hotel.permission:expense.review')->middleware('throttle:20,1');
            Route::post('/expenses/{expense}/review', [ExpenseController::class, 'review'])->whereNumber('expense')->middleware('hotel.permission:expense.review')->middleware('throttle:20,1');
            Route::post('/cashier-shifts/{shift}/expense-payouts', [ExpenseController::class, 'record'])->whereNumber('shift')->middleware('hotel.permission:cashier.write')->middleware('hotel.permission:expense.record')->middleware('throttle:20,1');
            Route::get('/cashier-shifts', [CashierShiftController::class, 'index'])->middleware('hotel.permission:cashier.read');
            Route::get('/cashier-shifts/current', [CashierShiftController::class, 'current'])->middleware('hotel.permission:cashier.read');
            Route::get('/cashier-shifts/{shift}', [CashierShiftController::class, 'show'])->whereNumber('shift')->middleware('hotel.permission:cashier.read');
            Route::get('/cashier-shifts/{shift}/history', [CashierShiftController::class, 'history'])->whereNumber('shift')->middleware('hotel.permission:cashier.read');
            Route::post('/cashier-shifts', [CashierShiftController::class, 'open'])->middleware('hotel.permission:cashier.write')->middleware('throttle:20,1');
            Route::post('/cashier-shifts/{shift}/bookings/{booking}/cash-receipts', [CashierShiftController::class, 'receive'])
                ->whereNumber('shift')->whereNumber('booking')->middleware('hotel.permission:cashier.write')->middleware('throttle:30,1');
            Route::post('/cashier-shifts/{shift}/close', [CashierShiftController::class, 'close'])
                ->whereNumber('shift')->middleware('hotel.permission:cashier.write')->middleware('throttle:20,1');

            // Independent observation: assigned housekeeping cannot edit bookings;
            // front desk cannot submit or inspect occupancy verification evidence.
            Route::get('/occupancy-verifications', [OccupancyVerificationController::class, 'index'])
                ->middleware('hotel.permission:occupancy.read');
            Route::get('/occupancy-verifications/{verification}', [OccupancyVerificationController::class, 'show'])
                ->whereNumber('verification')->middleware('hotel.permission:occupancy.read');
            Route::get('/occupancy-verifications/{verification}/history', [OccupancyVerificationController::class, 'history'])
                ->whereNumber('verification')->middleware('hotel.permission:occupancy.read');
            Route::post('/occupancy-verifications/{verification}/observe', [OccupancyVerificationController::class, 'observe'])
                ->whereNumber('verification')->middleware('hotel.permission:occupancy.observe')
                ->middleware('throttle:20,1');

            Route::get('/keycard-authorizations', [KeycardAuthorizationController::class, 'index'])
                ->middleware('hotel.permission:keycard.read');
            Route::get('/keycard-authorizations/{authorization}/history', [KeycardAuthorizationController::class, 'history'])
                ->whereNumber('authorization')->middleware('hotel.permission:keycard.read');
            Route::post('/keycard-authorizations', [KeycardAuthorizationController::class, 'store'])
                ->middleware('hotel.permission:keycard.request')->middleware('throttle:20,1');

            Route::middleware('hotel.admin')->group(function (): void {
                // Tax preview is explicitly nonposting; approval does not touch guest balances.
                Route::get('/bookings/{booking}/folio-night-entries', [FolioNightAssessmentController::class, 'nights'])
                    ->whereNumber('booking');
                Route::get('/bookings/{booking}/folio-night-assessments', [FolioNightAssessmentController::class, 'index'])
                    ->whereNumber('booking');
                Route::post('/bookings/{booking}/folio-night-assessments', [FolioNightAssessmentController::class, 'prepare'])
                    ->whereNumber('booking')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/folio-night-assessments/{assessment}/approve', [FolioNightAssessmentController::class, 'approve'])
                    ->whereNumber('booking')->whereNumber('assessment')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/folio-night-assessments/{assessment}/recognize', [FolioNightAssessmentController::class, 'recognize'])
                    ->whereNumber('booking')->whereNumber('assessment')->middleware('throttle:10,1');
                Route::get('/bookings/{booking}/folio-night-assessments/{assessment}/corrections', [FolioNightCorrectionController::class, 'index'])
                    ->whereNumber('booking')->whereNumber('assessment');
                Route::post('/bookings/{booking}/folio-night-assessments/{assessment}/corrections', [FolioNightCorrectionController::class, 'prepare'])
                    ->whereNumber('booking')->whereNumber('assessment')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/folio-night-assessments/{assessment}/corrections/{correction}/approve', [FolioNightCorrectionController::class, 'approve'])
                    ->whereNumber('booking')->whereNumber('assessment')->whereNumber('correction')->middleware('throttle:5,1');
                Route::post('/bookings/{booking}/payment-records/{payment}/verification', [FolioSettlementController::class, 'verifyPrepare'])
                    ->whereNumber('booking')->whereNumber('payment')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/settlement-verifications/{verification}/review', [FolioSettlementController::class, 'verifyReview'])
                    ->whereNumber('booking')->whereNumber('verification')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/payment-records/{payment}/allocations', [FolioSettlementController::class, 'allocations'])
                    ->whereNumber('booking')->whereNumber('payment')->middleware('throttle:20,1');
                Route::get('/bookings/{booking}/refund-disbursements', [FolioRefundDisbursementController::class, 'index'])
                    ->whereNumber('booking');
                Route::post('/bookings/{booking}/refund-requests/{refund}/disbursement-evidence', [FolioRefundDisbursementController::class, 'record'])
                    ->whereNumber('booking')->whereNumber('refund')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/refund-disbursements/{evidence}/review', [FolioRefundDisbursementController::class, 'review'])
                    ->whereNumber('booking')->whereNumber('evidence')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/settlement-signoff', [FolioSettlementController::class, 'signoff'])
                    ->whereNumber('booking')->middleware('throttle:5,1');
                Route::get('/finance/tax-policies', [FinancialTaxPolicyController::class, 'index']);
                Route::post('/finance/tax-policies', [FinancialTaxPolicyController::class, 'draft'])
                    ->middleware('throttle:10,1');
                Route::post('/finance/tax-policies/{policy}/approve', [FinancialTaxPolicyController::class, 'approve'])
                    ->whereNumber('policy')->middleware('throttle:5,1');
                Route::get('/finance/tax-preview', [FinancialTaxPolicyController::class, 'preview']);
                Route::get('/finance/business-day', [FinancialBusinessDayController::class, 'show']);
                Route::post('/finance/business-day/initialize', [FinancialBusinessDayController::class, 'initialize'])
                    ->middleware('throttle:5,1,business-date-setup');
                Route::post('/finance/business-day/close', [FinancialBusinessDayController::class, 'close'])
                    ->middleware('throttle:5,1');
                Route::get('/night-audit/preview', NightAuditPreviewController::class);
                Route::get('/night-audit/review', [NightAuditReviewController::class,'show']);
                Route::post('/night-audit/review', [NightAuditReviewController::class,'store'])->middleware('throttle:10,1');
                Route::get('/occupancy-coverage-report', OccupancyCoverageReportController::class);
                Route::post('/keycard-authorizations/{authorization}/review', [KeycardAuthorizationController::class, 'review'])
                    ->whereNumber('authorization')->middleware('throttle:20,1');
                Route::post('/keycard-authorizations/{authorization}/revoke', [KeycardAuthorizationController::class, 'revoke'])
                    ->whereNumber('authorization')->middleware('throttle:20,1');
                Route::get('/occupancy-verifications/{verification}/investigation', [OccupancyInvestigationController::class, 'show'])
                    ->whereNumber('verification');
                Route::post('/occupancy-verifications/{verification}/investigation', [OccupancyInvestigationController::class, 'store'])
                    ->whereNumber('verification')->middleware('throttle:10,1');
                Route::post('/occupancy-verifications/{verification}/investigation/action', [OccupancyInvestigationController::class, 'update'])
                    ->whereNumber('verification')->middleware('throttle:20,1');
                Route::post('/occupancy-verifications', [OccupancyVerificationController::class, 'assign'])
                    ->middleware('throttle:30,1');
                Route::post('/occupancy-verifications/{verification}/review', [OccupancyVerificationController::class, 'review'])
                    ->whereNumber('verification')->middleware('throttle:20,1');
                Route::post('/cashier-shifts/{shift}/reconcile', [CashierShiftController::class, 'reconcile'])
                    ->whereNumber('shift')->middleware('throttle:20,1');
                Route::post('/approval-requests/{approval}/review', [ManagerApprovalController::class, 'review'])
                    ->whereNumber('approval')->middleware('throttle:20,1');
                Route::patch('/', [HotelController::class, 'update']);
                Route::get('/operational-exceptions', OperationalExceptionsController::class);
                Route::get('/staff', [HotelStaffController::class, 'index']);
                Route::get('/staff/permissions', [HotelStaffController::class, 'permissions']);
                Route::get('/staff/invitations', [StaffInvitationController::class, 'index']);
                Route::post('/staff/invitations', [StaffInvitationController::class, 'store'])->middleware('throttle:10,1');
                Route::patch('/staff/invitations/{invitation}/revoke', [StaffInvitationController::class, 'revoke'])->whereNumber('invitation');
                Route::get('/staff/history', [HotelStaffController::class, 'history']);
                Route::post('/staff/create-account', [HotelStaffController::class, 'createAccount'])->middleware('throttle:5,1');
                Route::post('/staff', [HotelStaffController::class, 'attach']);
                Route::patch('/staff/{userId}', [HotelStaffController::class, 'update'])->whereNumber('userId');
                Route::post('/inventory-blocks', [RoomInventoryController::class, 'block']);
                Route::post('/inventory-blocks/{block}/release', [RoomInventoryController::class, 'release'])->whereNumber('block');
                Route::post('/rate-plans', [ReservationFinanceController::class, 'createRate']);
                Route::get('/rate-plans/{plan}/booking-policies', [\App\Http\Controllers\Api\RateBookingPolicyController::class, 'index'])->whereNumber('plan');
                Route::post('/rate-plans/{plan}/booking-policies', [\App\Http\Controllers\Api\RateBookingPolicyController::class, 'store'])->whereNumber('plan')->middleware('throttle:10,1');
                Route::get('/rate-plans/{plan}/booking-policy-preview', [\App\Http\Controllers\Api\RateBookingPolicyController::class, 'preview'])->whereNumber('plan');
                Route::get('/rate-plans/{plan}/rules', [\App\Http\Controllers\Api\SeasonalRateController::class, 'index'])->whereNumber('plan');
                Route::post('/rate-plans/{plan}/bulk-overrides', [\App\Http\Controllers\Api\SeasonalRateController::class, 'bulk'])->whereNumber('plan')->middleware('throttle:10,1');
                Route::post('/rate-plans/{plan}/rules', [\App\Http\Controllers\Api\SeasonalRateController::class, 'store'])->whereNumber('plan')->middleware('throttle:20,1');
                Route::put('/rate-plans/{plan}/rules/{rule}', [\App\Http\Controllers\Api\SeasonalRateController::class, 'update'])->whereNumber('plan')->whereNumber('rule')->middleware('throttle:20,1');
                Route::post('/rate-plans/{plan}/rules/{rule}/retire', [\App\Http\Controllers\Api\SeasonalRateController::class, 'retire'])->whereNumber('plan')->whereNumber('rule')->middleware('throttle:20,1');
                Route::get('/rate-plans/{plan}/seasonal-preview', [\App\Http\Controllers\Api\SeasonalRateController::class, 'preview'])->whereNumber('plan');
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
                Route::post('/bookings/{booking}/payment-records/{payment}/void', [FolioAccountingController::class, 'voidPayment'])
                    ->whereNumber('booking')->whereNumber('payment')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/dates/preview', [FrontDeskReservationController::class, 'previewAmendment'])->whereNumber('booking')->middleware('throttle:30,1');
                Route::patch('/bookings/{booking}/dates', [FrontDeskReservationController::class, 'amend'])->whereNumber('booking');
                Route::get('/bookings/{booking}/no-show-ledger-reconciliation', [\App\Http\Controllers\Api\NoShowFinancialReviewController::class, 'ledgerReconciliation'])->whereNumber('booking')->middleware('hotel.permission:finance.read')->middleware('throttle:30,1');
                Route::get('/bookings/{booking}/no-show-financial-review', [\App\Http\Controllers\Api\NoShowFinancialReviewController::class, 'show'])->whereNumber('booking')->middleware('hotel.permission:finance.read');
                Route::post('/bookings/{booking}/no-show-financial-review', [\App\Http\Controllers\Api\NoShowFinancialReviewController::class, 'apply'])
                    ->whereNumber('booking')->middleware('hotel.permission:finance.read')->middleware('throttle:10,1');
                Route::post('/bookings/{booking}/no-show-financial-review/{review}/approve', [\App\Http\Controllers\Api\NoShowFinancialReviewController::class, 'approve'])
                    ->whereNumber('booking')->whereNumber('review')->middleware('hotel.permission:finance.read')->middleware('throttle:10,1');
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

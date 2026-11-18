<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class FinanceFolioAccessRouteTest extends TestCase
{
    public function test_billing_list_requires_hotel_membership_and_finance_read_permission(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v1/hotels/1/finance/folios', 'GET')
        );
        $middleware = $route->gatherMiddleware();
        self::assertContains('hotel.member', $middleware);
        self::assertContains('hotel.permission:finance.read', $middleware);
        self::assertContains('auth:sanctum', $middleware);
        self::assertContains('active.user', $middleware);
    }

    public function test_current_staff_cashier_shift_lookup_requires_cashier_read_permission(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v1/hotels/1/cashier-shifts/current', 'GET')
        );
        $middleware = $route->gatherMiddleware();
        self::assertContains('hotel.member', $middleware);
        self::assertContains('hotel.permission:cashier.read', $middleware);
        self::assertContains('auth:sanctum', $middleware);
        self::assertSame([], $route->parameterNames());
    }

    public function test_financial_write_routes_require_hotel_administrator(): void
    {
        foreach ([
            '/api/v1/hotels/1/bookings/2/folio-adjustments',
            '/api/v1/hotels/1/bookings/2/payment-records',
            '/api/v1/hotels/1/bookings/2/settlement-signoff',
        ] as $url) {
            $route = Route::getRoutes()->match(Request::create($url, 'POST'));
            self::assertContains('hotel.admin', $route->gatherMiddleware(), $url);
        }
    }
}

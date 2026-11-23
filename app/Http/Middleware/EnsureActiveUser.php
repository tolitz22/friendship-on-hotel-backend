<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            abort(403, 'Your user account is inactive.');
        }

        // An administrator knows the initial password. Require the employee to
        // replace it before granting access to any hotel data or operations.
        if ($request->user()->must_change_password
            && ! $request->is('api/v1/auth/me')
            && ! $request->is('api/v1/auth/change-password')
            && ! $request->is('api/v1/auth/logout')
            && ! $request->is('api/v1/auth/spa-logout')) {
            abort(403, 'Change your initial password before using the hotel system.');
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(PlatformAccess::allowed($request->user()), 403, 'Platform administrator access required.');

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Logging\SecurityLog;
use App\Logging\MonitoringRoutes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TrackApiRequest
{
    public const ATTRIBUTE = '_pms_request_id';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api', 'api/*') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        // Never trust a client-provided request ID (including valid UUIDs).
        $id = (string) Str::uuid();
        $started = hrtime(true);
        $request->attributes->set(self::ATTRIBUTE, $id);
        $status = 500;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('X-Request-ID', $id);
            $securityEvent = match ($status) {
                401 => 'pms.security.access.unauthenticated',
                403 => 'pms.security.access.denied',
                419 => 'pms.security.access.csrf_rejected',
                429 => 'pms.security.access.rate_limited',
                default => null,
            };
            if ($securityEvent !== null) {
                $context = ['status_code' => $status];
                $route = $request->route();
                if ($status === 429 && $route instanceof Route && MonitoringRoutes::contains($route->uri())) {
                    $context['route_template'] = $route->uri();
                }
                SecurityLog::record($securityEvent, $context, true);
            }

            return $response;
        } finally {
            try {
                $route = $request->route();
                $level = $status >= 500 ? 'error' : ($status === 429 ? 'warning' : 'info');
                Log::log($level, 'pms.api.request.completed', [
                    'request_id' => $id,
                    'http_method' => $request->getMethod(),
                    'route_template' => $route instanceof Route ? $route->uri() : 'unmatched',
                    'status_code' => $status,
                    'duration_ms' => max(0, (int) round((hrtime(true) - $started) / 1000000)),
                ]);
            } catch (Throwable) {
                // A broken logging destination must not change the API response.
            } finally {
                $request->attributes->remove(self::ATTRIBUTE);
            }
        }
    }
}

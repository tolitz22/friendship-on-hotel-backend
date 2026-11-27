<?php

declare(strict_types=1);

namespace App\Logging;

use Closure;
use Monolog\LogRecord;
use Throwable;

final class SafeStructuredLogProcessor
{
    // Only reviewed, static event names may become externally visible messages.
    private const EVENTS = [
        'pms.logging.smoke',
        'pms.api.request.completed',
        FrontendErrorLog::EVENT,
    ];

    private const NUMERIC_FIELDS = [
        'hotel_id', 'user_id', 'actor_id', 'booking_id', 'reservation_id',
        'payment_id', 'shift_id', 'job_id', 'status_code', 'duration_ms',
        'target_user_id', 'invitation_id',
        'attempt', 'count',
    ];

    public function __construct(
        private readonly string $environment,
        private readonly ?Closure $requestId = null,
        private readonly string $serviceRole = 'unspecified',
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = [];
        foreach (self::NUMERIC_FIELDS as $key) {
            $value = $record->context[$key] ?? null;
            if (is_int($value) && $value >= 0) {
                $context[$key] = $value;
            } elseif (is_string($value) && preg_match('/\A[0-9]{1,18}\z/D', $value) === 1) {
                $context[$key] = (int) $value;
            }
        }

        // Context from the current server request takes precedence.
        $requestId = $this->requestId !== null ? ($this->requestId)() : null;
        $requestId ??= $record->context['request_id'] ?? null;
        if (is_string($requestId) && preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D', $requestId) === 1) {
            $context['request_id'] = $requestId;
        }

        if ($record->message === 'pms.api.request.completed') {
            $method = $record->context['http_method'] ?? null;
            if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
                $context['http_method'] = $method;
            }
            $route = $record->context['route_template'] ?? null;
            if (is_string($route) && preg_match('/\\A(?:unmatched|api(?:\\/[A-Za-z0-9_{}?\\-]+)*)\\z/D', $route) === 1 && strlen($route) <= 180) {
                $context['route_template'] = $route;
            }
        }

        if (in_array($record->message, SecurityLog::EVENTS, true)) {
            $mode = $record->context['auth_mode'] ?? null;
            if (in_array($mode, ['bearer', 'cookie'], true)) {
                $context['auth_mode'] = $mode;
            }
        }

        if ($record->message === 'pms.security.access.rate_limited' && MonitoringRoutes::contains($record->context['route_template'] ?? null)) {
            $context['route_template'] = $record->context['route_template'];
        }

        if ($record->message === FrontendErrorLog::EVENT) {
            unset($context['status_code']);
            $context += FrontendErrorLog::metadata($record->context);
        }

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $class = get_class($exception);
            $context['exception_type'] = preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,199}\z/D', $class) === 1 ? $class : 'Throwable';
            $file = basename($exception->getFile());
            if (preg_match('/\A[A-Za-z0-9_.-]{1,120}\.php\z/D', $file) === 1) {
                $context['exception_file'] = $file;
                $context['exception_line'] = $exception->getLine();
            }
        }

        // Free-form log messages may contain SQL, tokens, URLs or personal data.
        // Retain the level and safe diagnostic metadata, not the raw text.
        $event = (in_array($record->message, self::EVENTS, true) || in_array($record->message, SecurityLog::EVENTS, true))
            ? $record->message
            : ($exception instanceof Throwable ? 'pms.application.exception' : 'pms.application.log');

        return $record->with(
            message: $event,
            context: $context,
            extra: [
                'service' => 'friendship-on-hotel-backend',
                'environment' => in_array($this->environment, ['local', 'testing', 'staging', 'production'], true) ? $this->environment : 'other',
                'service_role' => ServiceRole::normalize($this->serviceRole),
                'schema_version' => 1,
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Throwable;

final class SafeStructuredLogProcessor
{
    // Only reviewed, static event names may become externally visible messages.
    private const EVENTS = [
        'pms.logging.smoke',
    ];

    private const NUMERIC_FIELDS = [
        'hotel_id', 'user_id', 'actor_id', 'booking_id', 'reservation_id',
        'payment_id', 'shift_id', 'job_id', 'status_code', 'duration_ms',
        'attempt', 'count',
    ];

    public function __construct(private readonly string $environment)
    {
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
        $event = in_array($record->message, self::EVENTS, true)
            ? $record->message
            : ($exception instanceof Throwable ? 'pms.application.exception' : 'pms.application.log');

        return $record->with(
            message: $event,
            context: $context,
            extra: [
                'service' => 'friendship-on-hotel-backend',
                'environment' => in_array($this->environment, ['local', 'testing', 'staging', 'production'], true) ? $this->environment : 'other',
                'schema_version' => 1,
            ],
        );
    }
}

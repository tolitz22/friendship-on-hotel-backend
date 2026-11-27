<?php

declare(strict_types=1);

namespace App\Logging;

use App\Http\Middleware\TrackApiRequest;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;

final class ConfigureStructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        $processor = new SafeStructuredLogProcessor(
            (string) config('app.env', 'production'),
            static function (): ?string {
                if (! app()->bound('request')) {
                    return null;
                }
                $request = app('request');
                $id = $request instanceof Request ? $request->attributes->get(TrackApiRequest::ATTRIBUTE) : null;

                return is_string($id) ? $id : null;
            },
        );

        // Attach at the handler level so Laravel stack channels do not apply
        // the same redaction processor multiple times.
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface && $handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
                $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, false, false));
            }
        }
    }
}

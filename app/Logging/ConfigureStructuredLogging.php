<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;

final class ConfigureStructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        $processor = new SafeStructuredLogProcessor((string) config('app.env', 'production'));

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

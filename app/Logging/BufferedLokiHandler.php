<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Optional best-effort exporter. The normal stderr channel remains primary.
 * A bounded batch is sent at reset/close or PHP shutdown, never per log entry.
 */
final class BufferedLokiHandler extends AbstractProcessingHandler
{
    private const MAX_RECORDS = 40;
    private const MAX_BYTES = 65536;
    private array $streams = [];
    private int $records = 0;
    private int $bytes = 0;
    private int $dropped = 0;
    private bool $registered = false;
    private bool $flushing = false;
    private float $retryAfter = 0;
    private ?bool $lastAccepted = null;
    private ?int $lastHttpStatus = null;
    private string $lastOutcome = 'no_batch_sent';

    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $url = '',
        private readonly string $username = '',
        private readonly string $token = '',
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
        private readonly string $protocol = 'loki',
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (! $this->enabled || $this->flushing || microtime(true) < $this->retryAfter
            || ! LokiSettings::valid($this->url, $this->username, $this->token, $this->protocol)) {
            return;
        }

        // Fail closed unless the LOG-1 tap has produced the expected safe JSON.
        if (! is_string($record->formatted) || ($record->extra['schema_version'] ?? null) !== 1
            || ($record->extra['service'] ?? null) !== 'friendship-on-hotel-backend') {
            return;
        }

        $line = rtrim($record->formatted, "\r\n");
        if ($this->records >= self::MAX_RECORDS || $this->bytes + strlen($line) > self::MAX_BYTES) {
            $this->dropped++;
            return;
        }

        $environment = $record->extra['environment'] ?? 'other';
        if (! in_array($environment, ['local', 'testing', 'staging', 'production'], true)) {
            $environment = 'other';
        }
        $severity = strtolower($record->level->getName());
        $key = $environment.':'.$severity;
        $this->streams[$key] ??= [
            'stream' => ['service' => 'friendship-on-hotel-backend', 'environment' => $environment, 'level' => $severity],
            'values' => [],
        ];
        // Loki expects a string containing Unix epoch nanoseconds.
        $this->streams[$key]['values'][] = [$record->datetime->format('Uu').'000', $line];
        $this->records++;
        $this->bytes += strlen($line);

        if (! $this->registered) {
            $this->registered = true;
            register_shutdown_function(fn () => $this->close());
        }
    }

    public function close(): void
    {
        if ($this->flushing || $this->records === 0) {
            return;
        }
        $streams = array_values($this->streams);
        $dropped = $this->dropped;
        $this->streams = [];
        $this->records = $this->bytes = $this->dropped = 0;
        $this->flushing = true;
        $this->lastAccepted = false;
        $this->lastHttpStatus = null;
        $this->lastOutcome = 'export_exception';
        try {
            $payload = $this->protocol === 'otlp'
                ? OtlpLogPayload::fromStreams($streams)
                : ['streams' => $streams];
            $response = Http::withBasicAuth($this->username, $this->token)
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(1)->timeout(2)
                ->acceptJson()
                ->post($this->url, $payload);
            $this->lastHttpStatus = $response->status();
            $this->lastAccepted = $this->protocol === 'otlp'
                ? ($response->status() === 204 || ($response->status() === 200 && OtlpLogPayload::fullyAccepted($response->body())))
                : $response->status() === 204;
            $this->lastOutcome = match (true) {
                $this->lastAccepted => 'accepted',
                in_array($this->lastHttpStatus, [401, 403], true) => 'authentication_or_scope',
                $this->lastHttpStatus === 400 => 'bad_request',
                $this->lastHttpStatus === 404 => 'endpoint_not_found',
                $this->lastHttpStatus === 415 => 'unsupported_media_type',
                $this->lastHttpStatus === 429 => 'rate_limited',
                $this->lastHttpStatus >= 500 => 'remote_server_error',
                $this->protocol === 'otlp' && $this->lastHttpStatus === 200 => 'otlp_unconfirmed_response',
                default => 'unexpected_http_status',
            };
            if (! $this->lastAccepted) {
                $this->retryAfter = microtime(true) + 30;
                error_log('pms.loki.export_failed');
            }
        } catch (ConnectionException) {
            $this->lastOutcome = 'connection_or_timeout';
            $this->retryAfter = microtime(true) + 30;
            error_log('pms.loki.export_failed');
        } catch (Throwable) {
            $this->retryAfter = microtime(true) + 30;
            // Never send endpoint, credentials, response or exception to logs.
            error_log('pms.loki.export_failed');
        } finally {
            if ($dropped > 0) {
                error_log('pms.loki.batch_limit_reached');
            }
            $this->flushing = false;
        }
    }

    public function lastDeliveryAccepted(): ?bool
    {
        return $this->lastAccepted;
    }

    /** Only fixed diagnostic codes and integer HTTP status; never raw remote data. */
    public function deliveryDiagnostic(): array
    {
        return ['outcome' => $this->lastOutcome, 'http_status' => $this->lastHttpStatus];
    }

    public function reset(): void
    {
        $this->close();
        parent::reset();
    }
}

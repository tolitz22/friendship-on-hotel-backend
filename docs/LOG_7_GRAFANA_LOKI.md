# LOG-7 — Grafana Loki integration

## Design

Railway stderr stays primary. An optional Monolog handler exports already-redacted JSON to a HTTPS Loki push endpoint (Grafana Cloud or an authenticated compatible endpoint). No new Railway service, volume, database table, migration or worker is required for Grafana Cloud. Export is disabled by default. Do not deploy a public unauthenticated Loki instance. Use a separate least-privilege write-only token (logs:write), not a Grafana admin token. The endpoint must be the trusted destination selected by the operator. No external transfer is activated by this commit.

The handler buffers up to 40 records and 64 KiB of log-line content in memory, then sends at logger reset, close or PHP shutdown with a 1-second connection and 2-second total timeout. No automatic retry or persistent spool. Overflow and failed delivery are best effort, with fixed diagnostic messages written to stderr; a 30-second cooldown applies within that handler instance only. In long-running workers, logs are flushed on reset/close/shutdown, not by a periodic timer. On PHP-FPM the network operation can still occupy a worker during shutdown. Use default WARNING level initially; INFO captures routine API requests but increases cost and dropped batches. For reliable high-volume delivery, deploy an external collector with durable buffering rather than raising limits. This is not a complete Railway log drain: raw PHP, platform and non-Laravel logs remain in Railway. No guest data, raw stack traces, request bodies, or credentials are intentionally exported; internal user/hotel IDs remain in safe JSON. Restrict Grafana access to platform operators and review data processing/retention before enabling.

## Setup (backend service only)

In Grafana Cloud create a Logs instance and obtain the Loki push URL, instance ID, and a scoped write token. Set Railway variables:

    LOG_CHANNEL=stack
    LOG_STACK=stderr,loki
    LOG_LEVEL=info
    PMS_LOKI_ENABLED=true
    PMS_LOKI_URL=https://<your-loki-host>/loki/api/v1/push
    PMS_LOKI_USERNAME=<instance-id>
    PMS_LOKI_TOKEN=<secret-write-token>
    PMS_LOKI_LEVEL=warning

Set the secret in Railway directly, never in source code or chat. Redeploy. Existing stderr is retained. To disable, set PMS_LOKI_ENABLED=false and LOG_CHANNEL=stderr. Retention is provider-controlled; LOG-6 targets are not automatically applied.

## Verify

    php artisan pms:loki-check
    php artisan pms:loki-check --send

The first command checks config only. The second submits a synthetic warning and does not guarantee delivery. In Grafana Explore select Loki and search:

    {service="friendship-on-hotel-backend"} | json | message="pms.logging.smoke"
    {service="friendship-on-hotel-backend",level="error"} | json
    {service="friendship-on-hotel-backend"} | json | context_request_id="<request-id>"
    {service="friendship-on-hotel-backend"} | json | message="pms.frontend.error_reported"

Only service, environment, level are indexed labels. IDs are JSON fields, not high-cardinality labels. Use INFO level to export routine API completion events; warnings/errors only is the default.

Run php artisan test tests/Feature/LokiLoggingTest.php tests/Feature/LogRetentionTest.php tests/Unit/SafeStructuredLoggingTest.php. Tests fake HTTP; actual Grafana delivery requires credentials and live verification. Grafana Loki HTTP API: https://grafana.com/docs/loki/latest/reference/loki-http-api/. Railway logging: https://docs.railway.com/observability/logs.

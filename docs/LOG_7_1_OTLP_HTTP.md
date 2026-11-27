# LOG-7.1 — Grafana Cloud OTLP/HTTP JSON

The optional LOG-7 exporter now supports two protocols without changing the default native Loki behavior. PMS_LOKI_PROTOCOL=loki uses /loki/api/v1/push and HTTP 204; PMS_LOKI_PROTOCOL=otlp uses /otlp/v1/logs, OTLP JSON resourceLogs, nanosecond timestamps encoded as strings, numeric severity and HTTP 200 JSON acknowledgement (or HTTP 204 no-content success observed at the Grafana gateway). Rejected records in partialSuccess are treated as failure; no response content or credentials are printed. The original sanitized JSON line is retained as the body for LogQL parsing. No new dependencies, tables, service or queue.

For the Grafana Cloud HTTP onboarding endpoint set backend variables:

    LOG_CHANNEL=stack
    LOG_STACK=stderr,loki
    LOG_LEVEL=info
    PMS_LOKI_ENABLED=true
    PMS_LOKI_PROTOCOL=otlp
    PMS_LOKI_URL=https://<your-otlp-gateway>/otlp/v1/logs
    PMS_LOKI_USERNAME=<OTLP instance ID, not native Loki ID>
    PMS_LOKI_TOKEN=<new scoped logs:write access token>
    PMS_LOKI_LEVEL=warning

Use a new token; revoke any exposed Basic authorization credential. Do not paste secrets into chat, commits or documentation. Do not use internal provisioned data source URLs. Redeploy after settings change. The integration is disabled by default; no live settings are modified by this code.

    php artisan pms:loki-check
    php artisan pms:loki-check --send

First checks configuration; second requires acknowledgement from the remote endpoint. Acknowledgement is not proof of query visibility. In Grafana Explore choose the existing Loki source and use:

    {service_name="friendship-on-hotel-backend"} | json | message="pms.logging.smoke"

OTLP service.name typically maps to service_name; inspect available labels if different. Existing native Loki queries use service instead. WARNING is initial export threshold; INFO increases volume and includes routine request completions. Existing bounded best-effort shutdown flushing, cooldown, privacy filter and stderr fallback are unchanged. Offline or rejected events may be lost.

Tests: php artisan test tests/Feature/OtlpLoggingTest.php tests/Feature/LokiLoggingTest.php tests/Feature/LogRetentionTest.php. HTTP tests are faked; live Grafana acceptance must be confirmed separately.

References: https://opentelemetry.io/docs/specs/otlp/ and https://grafana.com/docs/grafana-cloud/send-data/otlp/send-data-otlp/.

On failed smoke delivery the command now prints a fixed outcome code and HTTP status (or not received). 401/403: check gateway instance ID, token validity, stack realm and logs:write. 400/415: payload or media type. 404: endpoint. 429: quota/rate. 5xx: gateway. Connection or timeout: network/TLS/DNS or the short export timeout. HTTP 200 with otlp_unconfirmed_response: response not recognized or records rejected; inspect Grafana for the synthetic event before assuming failure. No remote body, token, or exception details are printed.

Grafana gateway compatibility: HTTP 204 is accepted as successful transport acknowledgement for OTLP as well as native Loki. Standard OTLP HTTP 200 still requires a valid JSON acknowledgement with no rejected records. Verify the smoke event in Explore; transport success alone does not prove indexing or query visibility.

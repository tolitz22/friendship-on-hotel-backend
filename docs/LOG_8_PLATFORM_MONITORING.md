# LOG-8 — Private platform monitoring

Create a dedicated account from the Railway backend shell: php artisan pms:platform-admin-create. The password is prompted interactively; no membership or privilege is granted by the command. Add its printed numeric ID to PMS_PLATFORM_ADMIN_IDS (comma separated) and redeploy. Hotel membership accounts, inactive accounts and accounts requiring a password change cannot access platform monitoring. Do not attach the dedicated account to a hotel. Keep its password private and restrict Railway access.

Use Grafana's existing Loki data source proxy. In Grafana create a dedicated read-only service account (Viewer or narrowly scoped datasource query permission), create a token, and obtain the Loki datasource UID from Connections > Data sources > Loki (URL ending in /edit/<uid>). Set only on the Railway API backend:

    PMS_MONITORING_GRAFANA_URL=https://<stack>.grafana.net
    PMS_MONITORING_LOKI_UID=<uid>
    PMS_MONITORING_READ_TOKEN=<service account token>
    PMS_MONITORING_SERVICE_LABEL=service_name

If Explore uses the service label instead, set service. Do not reuse the ingestion token, never use VITE_ for credentials, and never put the token in the frontend. The backend sends fixed, bounded read-only queries to Grafana's data source proxy; never accepts arbitrary LogQL, URLs or credentials. Source: https://grafana.com/docs/grafana/latest/developer-resources/api-reference/http-api/api-legacy/data_source/

Open /super-admin after signing in. Dashboard metrics are calculated from up to 500 recent sanitized exported records (not authoritative totals, uptime, complete request volume or all errors). WARNING threshold omits routine successful requests. To observe routine requests, set PMS_LOKI_LEVEL=info if cost/volume permits. Request ID lookup uses a bounded server-side filter. Empty data means no matching exported logs, not proof of service health. No database schema changes. No live Grafana read verification until credentials are configured.

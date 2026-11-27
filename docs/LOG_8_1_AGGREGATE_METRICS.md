# LOG-8.1 — Full-window exported-log metrics

The super-admin metrics endpoint uses three fixed Loki metric queries (count grouping, P95, 12 non-overlapping warning/error buckets), independently of the 500-row recent event list. The existing dedicated platform account and read token are reused. The metrics request is limited to six per minute per authenticated operator, with bounded ranges (15 min, 1h, 6h, 24h), 8-second timeout per parallel query, and no arbitrary LogQL. Each metric distinguishes unavailable results from a successful empty result. Counts and P95 describe exported logs only, not all real requests; warning-only export and transport loss limit coverage. Slow-route rankings remain explicitly sampled until a separate full-window per-route query is added. No new environment variables or database migrations. Live query acceptance is required against the configured Grafana data source.

Reference: https://grafana.com/docs/loki/latest/query/metric_queries/

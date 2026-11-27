# LOG-7.5 — Worker log delivery

Laravel 12 queue workers flush the already-resolved buffered Loki/OTLP handler at JobAttempted (successful or unsuccessful attempts), Looping (before polling again, including late exception reports) and WorkerStopping. Repeated hooks on an empty buffer perform no network operation. Shared handler instances are deduplicated. Existing shutdown and reset behavior remains active. Scheduler commands such as pms:push-deliver invoked by schedule:work or schedule:run normally execute in separate PHP processes and retain shutdown flushing; these hooks do not invent a heartbeat or enqueue new jobs.

The exporter remains best effort: 40 records / 64 KiB of safe lines per batch, 1 second connect and 2 seconds total HTTP timeout, 30 second cooldown per handler after failure. No retries, no database or durable spool. A single long job can overflow the buffer before its boundary; hard kills and crashes may lose pending events. Routine jobs without warning-level logs send nothing at the default threshold. Worker hooks do not change job acknowledgement or retry decisions. Stderr remains primary.

No new environment variables. Redeploy worker with existing OTLP variables and PMS_LOG_SERVICE_ROLE=worker. Run php artisan pms:loki-check --send for CLI connection check. In Grafana verify a real worker event (or an authorized synthetic job on the disposable demo queue) while the worker remains running, using:

    {service_name="friendship-on-hotel-backend"} | json | extra_service_role="worker"

If the service label is different, use the actual label from Grafana. A smoke command alone does not prove long-running job delivery. Test locally with php artisan test tests/Feature/WorkerLogFlushTest.php tests/Feature/OtlpLoggingTest.php tests/Feature/LokiLoggingTest.php. HTTP tests fake the gateway; live verification is separate.

Framework references: https://api.laravel.com/docs/12.x/Illuminate/Queue/Events/JobAttempted.html and https://api.laravel.com/docs/12.x/Illuminate/Log/LogManager.html.

# LOG-7.4 — Service identity

Set PMS_LOG_SERVICE_ROLE independently on each Railway service: backend for the HTTP API, worker for background jobs, scheduler if deployed separately. Do not share this variable between services. Missing or unrecognized values normalize to unspecified (existing deployments continue logging). APP_ENV remains the real Laravel environment, not a service identifier.

The original service name remains friendship-on-hotel-backend. New structured lines contain extra.service_role, native Loki has a bounded service_role stream label, OTLP includes service.role resource attribute. Grafana may store custom OTLP resource attributes as structured metadata rather than indexed labels; no changes to indexing are required for the following JSON body queries.

    {service_name="friendship-on-hotel-backend"} | json | extra_service_role="backend"
    {service_name="friendship-on-hotel-backend"} | json | extra_service_role="worker"
    {service_name="friendship-on-hotel-backend"} | json | extra_service_role="worker" | extra_environment="staging"
    {service_name="friendship-on-hotel-backend"} | json | extra_service_role="worker" | message="pms.logging.smoke"

If the Grafana gateway uses service instead of service_name, use its actual label from Explore. Native Loki can select {service="friendship-on-hotel-backend",service_role="worker",environment="staging"}. Old logs are not relabeled. For each service redeploy then run php artisan pms:loki-check and php artisan pms:loki-check --send, check the reported service role and find the corresponding new smoke event. Smoke command confirms CLI delivery only, not automatic long-running worker flush behavior. No new tables, secrets or service required. Role and environment fields are bounded and never taken from HTTP input.

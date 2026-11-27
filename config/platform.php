<?php

return [
    'deployment_revision' => env('RAILWAY_GIT_COMMIT_SHA', env('PMS_DEPLOYMENT_REVISION')),
    'admin_ids' => explode(',', (string) env('PMS_PLATFORM_ADMIN_IDS', '')),
    'monitoring' => [
        'url' => (string) env('PMS_MONITORING_GRAFANA_URL', ''),
        'datasource_uid' => (string) env('PMS_MONITORING_LOKI_UID', ''),
        'token' => (string) env('PMS_MONITORING_READ_TOKEN', ''),
        'service_label' => (string) env('PMS_MONITORING_SERVICE_LABEL', 'service_name'),
    ],
];

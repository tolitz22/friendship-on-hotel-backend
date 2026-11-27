<?php

declare(strict_types=1);

namespace App\Logging;

final class PlatformMonitoringConnection
{
    public static function settings(): ?array
    {
        $settings = config('platform.monitoring', []);
        $url = (string) ($settings['url'] ?? '');
        $uid = (string) ($settings['datasource_uid'] ?? '');
        $token = (string) ($settings['token'] ?? '');
        $label = (string) ($settings['service_label'] ?? '');
        if (! preg_match('~\Ahttps://[a-zA-Z0-9][a-zA-Z0-9-]*\.grafana\.net/?\z~D', $url)
            || ! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $uid)
            || $token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token)
            || ! in_array($label, ['service_name', 'service'], true)) {
            return null;
        }

        return ['endpoint' => rtrim($url, '/').'/api/datasources/proxy/uid/'.$uid.'/loki/api/v1/', 'token' => $token, 'label' => $label];
    }
}

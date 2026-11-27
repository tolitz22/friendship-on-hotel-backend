<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Throwable;

final class SecurityLog
{
    public const EVENTS = [
        'pms.security.platform.monitoring_viewed',
        'pms.security.platform.hotels_viewed',
        'pms.security.platform.hotel_created',
        'pms.security.platform.hotel_access_changed',
        'pms.security.platform.plan_created',
        'pms.security.platform.plan_updated',
        'pms.security.platform.subscription_changed',
        'pms.security.platform.subscription_invoice_issued',
        'pms.security.login.succeeded',
        'pms.security.login.failed',
        'pms.security.logout.succeeded',
        'pms.security.password.reset_requested',
        'pms.security.password.reset_succeeded',
        'pms.security.password.reset_failed',
        'pms.security.password.change_succeeded',
        'pms.security.password.change_failed',
        'pms.security.access.denied',
        'pms.security.access.unauthenticated',
        'pms.security.access.csrf_rejected',
        'pms.security.access.rate_limited',
        'pms.security.access.membership_denied',
        'pms.security.staff.account_created',
        'pms.security.staff.attached',
        'pms.security.staff.updated',
        'pms.security.staff.permissions_updated',
        'pms.security.invitation.sent',
        'pms.security.invitation.revoked',
        'pms.security.invitation.accepted',
    ];

    public static function record(string $event, array $context = [], bool $warning = false): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            return;
        }

        try {
            // Even a local plaintext channel must receive only approved metadata.
            $safe = [];
            foreach (['user_id', 'actor_id', 'target_user_id', 'hotel_id', 'invitation_id', 'status_code'] as $key) {
                if (isset($context[$key]) && is_int($context[$key]) && $context[$key] > 0) {
                    $safe[$key] = $context[$key];
                }
            }
            if (in_array($context['auth_mode'] ?? null, ['bearer', 'cookie'], true)) {
                $safe['auth_mode'] = $context['auth_mode'];
            }
            if ($event === 'pms.security.access.rate_limited' && MonitoringRoutes::contains($context['route_template'] ?? null)) {
                $safe['route_template'] = $context['route_template'];
            }
            Log::log($warning ? 'warning' : 'info', $event, $safe);
        } catch (Throwable) {
            // Telemetry must never change authentication or a committed operation.
        }
    }
}

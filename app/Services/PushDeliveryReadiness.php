<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class PushDeliveryReadiness
{
    /** Return only safe diagnostic codes; never return configuration values. */
    public function configurationIssues(): array
    {
        $issues = [];
        if (! class_exists(WebPush::class) || ! class_exists(Subscription::class)) {
            $issues[] = 'transport_dependency_missing';
        }
        if (! $this->validKey(config('push.public_key'), 65, true)) {
            $issues[] = 'public_key_invalid';
        }
        if (! $this->validKey(config('push.private_key'), 32)) {
            $issues[] = 'private_key_invalid';
        }
        $subject = config('push.subject');
        if (! is_string($subject) || ! (
            (str_starts_with($subject, 'mailto:') && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL))
            || (str_starts_with($subject, 'https://') && filter_var($subject, FILTER_VALIDATE_URL))
        )) {
            $issues[] = 'subject_invalid';
        }
        return array_merge($issues, $this->scopeConfigurationIssues());
    }

    public function scopeConfigurationIssues(): array
    {
        $scope = config('push.scope', 'pilot');
        if ($scope === 'all') {
            return [];
        }
        if ($scope !== 'pilot') {
            return ['push_scope_invalid'];
        }
        $hotelId = config('push.hotel_id');

        return ! is_scalar($hotelId) || ! preg_match('/^[1-9][0-9]*$/D', (string) $hotelId)
            ? ['hotel_id_invalid'] : [];
    }

    public function scopeIssues(): array
    {
        $issues = $this->scopeConfigurationIssues();
        if ($issues !== [] || config('push.scope') === 'all') {
            return $issues;
        }
        $hotel = Hotel::find(config('push.hotel_id'));
        if (! $hotel) {
            return ['hotel_not_found'];
        }

        return $this->hotelAllowed($hotel) ? [] : ['non_demo_hotel_not_authorized'];
    }

    public function hotels(): Builder
    {
        $query = Hotel::query();
        if (config('push.scope') === 'all') {
            return $query;
        }
        if ($this->scopeConfigurationIssues() !== []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereKey(config('push.hotel_id'));
    }

    private function validKey(mixed $value, int $length, bool $public = false): bool
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return false;
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);

        return $raw !== false && strlen($raw) === $length
            && rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') === $value
            && ($public ? ord($raw[0]) === 4 : $raw !== str_repeat(chr(0), 32));
    }

    public function hotelAllowed(Hotel $hotel): bool
    {
        return config('push.scope') === 'all' || trim((string) $hotel->name) === 'Client Demo' || config('push.allow_non_demo') === true;
    }

    /** Lightweight check of the push schema; full event query smoke is opt-in. */
    public function schemaIssues(): array
    {
        $issues = [];
        foreach ([
            'push_subscriptions' => ['id', 'hotel_id', 'user_id', 'endpoint_hash', 'endpoint', 'p256dh', 'auth'],
            'push_endpoint_owners' => ['endpoint_hash', 'user_id'],
            'push_deliveries' => ['id', 'push_subscription_id', 'notification_id', 'kind', 'status', 'attempts', 'available_at', 'failure_code', 'sent_at'],
            'push_preferences' => array_merge(['hotel_id', 'user_id', 'enabled'], PushPreferences::KINDS),
        ] as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                $issues[] = 'schema_incomplete:'.$table;
            }
        }

        return $issues;
    }
}

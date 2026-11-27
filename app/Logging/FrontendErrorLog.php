<?php

declare(strict_types=1);

namespace App\Logging;

final class FrontendErrorLog
{
    public const EVENT = 'pms.frontend.error_reported';
    public const KINDS = ['react_render', 'javascript_error', 'unhandled_rejection', 'api_server_error', 'api_network_error', 'api_invalid_response', 'route_error'];
    public const SCREENS = ['dashboard', 'front-desk', 'reservations', 'group-reservations', 'rooms', 'room-calendar', 'billing', 'housekeeping', 'maintenance', 'expenses', 'cashier-shifts', 'staff', 'messages', 'operational-exceptions', 'manager-approvals', 'reports', 'settings', 'rate-calendar', 'night-audit', 'folio-night-accounting', 'occupancy-verification', 'lost-found', 'my-expense-claims', 'guests', 'auth', 'other'];
    public const ERROR_TYPES = ['Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'URIError', 'EvalError', 'AggregateError', 'Unknown'];
    public const REQUEST_ID_PATTERN = '/\\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D';

    public static function metadata(array $input): array
    {
        $safe = [];
        foreach (['kind' => self::KINDS, 'screen' => self::SCREENS, 'error_type' => self::ERROR_TYPES] as $key => $allowed) {
            if (in_array($input[$key] ?? null, $allowed, true)) {
                $safe[$key] = $input[$key];
            }
        }
        $status = $input['status_code'] ?? null;
        if (is_int($status) && ($status === 0 || ($status >= 500 && $status <= 599))) {
            $safe['status_code'] = $status;
        }
        $id = $input['related_request_id'] ?? null;
        if (is_string($id) && preg_match(self::REQUEST_ID_PATTERN, $id) === 1) {
            $safe['related_request_id'] = $id;
        }

        return $safe;
    }
}

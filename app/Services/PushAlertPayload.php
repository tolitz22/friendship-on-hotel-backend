<?php

declare(strict_types=1);

namespace App\Services;

final class PushAlertPayload
{
    public static function make(int $hotelId, array $alert): array
    {
        $kind = $alert['kind'];
        $params = ['hotel' => (string) $hotelId, 'booking' => $alert['bookingId']];
        if ($kind === 'room_assignment') {
            $params['deskAction'] = 'assign';
        } elseif (in_array($kind, ['checkout_due', 'checkout_overdue'], true)) {
            $params['deskAction'] = 'check-out';
        }

        return [
            'id' => $hotelId.':'.$alert['id'],
            'title' => 'Friendship On Hotel PMS',
            'body' => match ($kind) {
                'new_booking' => 'New booking received.',
                'checkout_overdue' => 'An overdue checkout requires attention.',
                'checkout_due' => 'A checkout is due today.',
                'room_assignment' => 'An arrival needs a room assignment.',
                'guest_check_in' => 'A guest has checked in.',
                'guest_check_out' => 'A guest has checked out.',
                'booking_cancelled' => 'A booking was cancelled.',
                'booking_no_show' => 'A booking was marked as no-show.',
                'stay_extended' => 'A reservation departure date was extended.',
                default => 'A hotel update requires attention.',
            },
            'url' => '/reservations?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        ];
    }
}

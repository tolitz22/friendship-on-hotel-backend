<?php

declare(strict_types=1);

namespace App\Services;

final class PushAlertPayload
{
    public static function make(int $hotelId, array $alert): array
    {
        $kind = $alert['kind'];
        $target = $alert['target'] ?? 'reservations';
        $params = ['hotel' => (string) $hotelId];
        if (isset($alert['bookingId'])) $params['booking'] = $alert['bookingId'];
        if (isset($alert['paymentId'])) $params['payment'] = $alert['paymentId'];
        if (isset($alert['expenseId'])) $params['expense'] = $alert['expenseId'];
        if (isset($alert['shiftId'])) $params['shift'] = $alert['shiftId'];
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
                'payment_recorded' => 'A guest payment was recorded.',
                'payment_pending_review' => 'A payment requires verification.',
                'payment_verified' => 'A payment was verified.',
                'payment_rejected' => 'A payment was rejected.',
                'payment_voided' => 'A payment was voided.',
                'refund_requested' => 'A refund was requested.',
                'refund_verified' => 'Refund disbursement evidence was verified.',
                'expense_recorded' => 'A hotel expense was recorded.',
                'expense_correction_requested' => 'An expense correction was requested.',
                'cashier_discrepancy' => 'A cashier cash-count discrepancy was reported.',
                default => 'A hotel update requires attention.',
            },
            'url' => '/'.$target.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        ];
    }
}

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
        foreach (['taskId' => 'task', 'orderId' => 'order', 'room' => 'room', 'calendarDate' => 'date', 'approvalId' => 'approval', 'conversationId' => 'conversation', 'closingDate' => 'date', 'itemId' => 'item'] as $field => $parameter) {
            if (isset($alert[$field])) {
                $params[$parameter] = $alert[$field];
            }
        }
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
                'hk_assigned' => 'A cleaning task has been assigned to you.',
                'hk_checkout_handoff' => 'Checkout cleaning needs an assignment.',
                'hk_arrival_urgent' => 'An arriving room needs cleaning or inspection.',
                'hk_overdue' => 'A housekeeping task is overdue.',
                'lost_found_registered' => 'A found item was registered.',
                'housekeeping_completed' => 'A housekeeping task was completed.',
                'room_inspection_approved' => 'A room inspection was approved.',
                'room_inspection_rejected' => 'A room inspection requires attention.',
                'maintenance_created' => 'A maintenance work order was created.',
                'maintenance_urgent' => 'An urgent maintenance work order requires attention.',
                'maintenance_resolved' => 'A maintenance work order was resolved.',
                'room_blocked' => 'A room inventory block was created.',
                'room_unblocked' => 'A room inventory block was released.',
                'approval_requested' => 'A manager approval is awaiting review.',
                'approval_approved' => 'A manager approval was granted.',
                'approval_rejected' => 'A manager approval was rejected.',
                'staff_message' => 'You have a new staff message.',
                'staff_announcement' => 'A staff announcement needs your acknowledgment.',
                'staff_handover' => 'A shift handover needs your acknowledgment.',
                'staff_acknowledged' => 'A staff member acknowledged your update.',
                'daily_close_completed' => 'Financial daily closing completed and the business date advanced.',
                'daily_close_blocked' => 'Financial daily closing has an unresolved blocker.',
                'closing_cashier_unreconciled' => 'Cashier shifts require reconciliation.',
                'closing_payment_pending' => 'Payment evidence requires review.',
                'closing_journal_integrity' => 'A financial journal requires integrity review.',
                'closing_no_show_unresolved' => 'A no-show financial review requires attention.',
                'closing_room_night_issue' => 'Nightly room accounting requires attention.',
                default => 'A hotel update requires attention.',
            },
            'url' => '/'.$target.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        ];
    }
}

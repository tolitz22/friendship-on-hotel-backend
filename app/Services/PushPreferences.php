<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class PushPreferences
{
    public const KINDS = ['new_booking', 'checkout_due', 'checkout_overdue', 'room_assignment', 'guest_check_in', 'guest_check_out', 'booking_cancelled', 'booking_no_show', 'stay_extended', 'payment_recorded', 'payment_pending_review', 'payment_verified', 'payment_rejected', 'payment_voided', 'refund_requested', 'refund_verified', 'expense_recorded', 'expense_correction_requested', 'cashier_discrepancy', 'housekeeping_completed', 'room_inspection_approved', 'room_inspection_rejected', 'maintenance_created', 'maintenance_urgent', 'maintenance_resolved', 'room_blocked', 'room_unblocked', 'approval_requested', 'approval_approved', 'approval_rejected', 'staff_message', 'staff_announcement', 'staff_handover', 'staff_acknowledged', 'daily_close_completed', 'daily_close_blocked', 'closing_cashier_unreconciled', 'closing_payment_pending', 'closing_journal_integrity', 'closing_no_show_unresolved', 'closing_room_night_issue'];

    public function get(int $hotelId, int $userId): array
    {
        $row = DB::table('push_preferences')->where('hotel_id', $hotelId)->where('user_id', $userId)->first();
        return $this->values($row);
    }

    /** Load preferences for a bounded recipient set with one query. */
    public function forUsers(int $hotelId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = DB::table('push_preferences')->where('hotel_id', $hotelId)->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $result = [];
        foreach ($userIds as $userId) {
            $result[$userId] = $this->values($rows->get($userId));
        }

        return $result;
    }

    private function values(?object $row): array
    {
        $result = ['enabled' => $row ? (bool) $row->enabled : true];
        foreach (self::KINDS as $kind) {
            $result[$kind] = $row ? (bool) $row->{$kind} : true;
        }

        return $result;
    }

    public function put(int $hotelId, int $userId, array $values): array
    {
        DB::table('push_preferences')->upsert([array_merge($values, [
            'hotel_id' => $hotelId, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ])], ['hotel_id', 'user_id'], array_merge(array_keys($values), ['updated_at']));

        return $this->get($hotelId, $userId);
    }

    public function allows(int $hotelId, int $userId, string $kind): bool
    {
        $preferences = $this->get($hotelId, $userId);

        return $preferences['enabled'] && ($preferences[$kind] ?? false) && in_array($kind, self::KINDS, true);
    }
}

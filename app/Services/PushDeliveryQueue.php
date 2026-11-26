<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PushDeliveryQueue
{
    public const MAX_ATTEMPTS = 5;

    private function scoped(int $hotelId): Builder
    {
        return DB::table('push_deliveries')->whereIn('push_subscription_id', DB::table('push_subscriptions')
            ->where('hotel_id', $hotelId)->select('id'));
    }

    public function enqueue(int $deviceId, array $alert): void
    {
        $this->enqueueBatch([['deviceId' => $deviceId, 'alert' => $alert]]);
    }

    /** Bounded insert batches stay below common SQL parameter limits. */
    public function enqueueBatch(iterable $entries): void
    {
        $rows = [];
        $now = now();
        foreach ($entries as $entry) {
            $rows[] = [
                'push_subscription_id' => $entry['deviceId'], 'notification_id' => $entry['alert']['id'],
                'kind' => $entry['alert']['kind'], 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($rows) >= 100) {
                DB::table('push_deliveries')->insertOrIgnore($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('push_deliveries')->insertOrIgnore($rows);
        }
    }

    /** Only immutable, short-window event IDs are safe to forget. */
    public function prune(int $hotelId, int $limit = 1000): int
    {
        $cutoff = now()->subDays(30);
        $query = $this->scoped($hotelId)->whereIn('status', ['sent', 'failed', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->whereIn('kind', [
                'new_booking', 'guest_check_in', 'guest_check_out', 'booking_cancelled',
                'booking_no_show', 'stay_extended', 'payment_recorded', 'payment_voided',
                'expense_recorded', 'room_inspection_approved', 'room_inspection_rejected',
                'maintenance_urgent', 'room_blocked', 'approval_approved', 'approval_rejected',
                'staff_message', 'staff_announcement', 'staff_handover', 'staff_acknowledged',
                'daily_close_completed',
            ]);
        $ids = (clone $query)->orderBy('id')->limit(max(1, min($limit, 1000)))->pluck('id');
        return $ids->isEmpty() ? 0 : $query->whereIn('id', $ids)->delete();
    }

    public function recover(int $hotelId): void
    {
        $expired = $this->scoped($hotelId)->where('status', 'processing')->where('available_at', '<=', now());
        (clone $expired)->where('attempts', '>=', self::MAX_ATTEMPTS)->update([
            'status' => 'failed', 'failure_code' => 'lease_exhausted', 'updated_at' => now(),
        ]);
        (clone $expired)->where('attempts', '<', self::MAX_ATTEMPTS)->update([
            'status' => 'pending', 'failure_code' => 'lease_expired', 'updated_at' => now(),
        ]);
        $this->scoped($hotelId)->where('status', 'pending')->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update(['status' => 'failed', 'failure_code' => 'attempts_exhausted', 'updated_at' => now()]);
        $this->scoped($hotelId)->where('status', 'pending')->where('created_at', '<=', now()->subDay())
            ->update(['status' => 'cancelled', 'failure_code' => 'delivery_expired', 'updated_at' => now()]);
    }

    public function ready(int $hotelId, int $limit = 100): iterable
    {
        // Age promotion prevents normal activity starving during a prolonged backlog.
        return $this->scoped($hotelId)->where('status', 'pending')->where('available_at', '<=', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '>', now()->subDay())
            ->orderByRaw("CASE WHEN created_at <= ? THEN 0 WHEN kind IN ('closing_journal_integrity','cashier_discrepancy','maintenance_urgent') THEN 1 WHEN kind IN ('checkout_overdue','payment_pending_review','payment_rejected','payment_voided','refund_requested','approval_requested','daily_close_blocked','closing_no_show_unresolved','closing_room_night_issue','closing_cashier_unreconciled','closing_payment_pending','staff_announcement','staff_handover') THEN 2 ELSE 3 END", [now()->subMinutes(15)])
            ->orderBy('id')->limit($limit)->get();
    }

    public function claim(object $row): bool
    {
        return DB::table('push_deliveries')->where('id', $row->id)->where('status', 'pending')
            ->where('attempts', $row->attempts)->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('available_at', '<=', now())->where('created_at', '>', now()->subDay())
            ->update(['status' => 'processing', 'attempts' => $row->attempts + 1,
                'available_at' => now()->addMinutes(5), 'updated_at' => now()]) === 1;
    }

    public function finish(object $row, string $status, ?string $code = null, ?int $retryAfterSeconds = null): bool
    {
        $attempt = $row->attempts + 1;
        if ($status === 'pending' && $attempt >= self::MAX_ATTEMPTS) {
            $status = 'failed';
        }
        $delay = max(60 * (2 ** min($attempt, 5)), min(86400, max(0, $retryAfterSeconds ?? 0)));

        return DB::table('push_deliveries')->where('id', $row->id)->where('status', 'processing')
            ->where('attempts', $attempt)->where('available_at', '>', now())->update([
                'status' => $status, 'failure_code' => $code,
                'available_at' => $status === 'pending' ? now()->addSeconds($delay) : now(),
                'sent_at' => $status === 'sent' ? now() : null, 'updated_at' => now(),
            ]) === 1;
    }

    public function counts(int $hotelId): array
    {
        return $this->scoped($hotelId)->select('status')->selectRaw('COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Api\FrontDeskReservationController;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

final class HotelNotificationService
{
    private function queues(Hotel $hotel): array
    {
        $clock = now($hotel->timezone ?: 'Asia/Manila');
        $today = $clock->toDateString();
        $bookings = DB::table('reservation_records')->where('hotel_id', $hotel->id);
        $departures = (clone $bookings)->where('lifecycle_state', 'checked_in');
        $arrivals = (clone $bookings)->where('lifecycle_state', 'confirmed');
        $overdueToday = $clock->format('H:i') >= FrontDeskReservationController::CHECKOUT_CUTOFF;

        $queues = [
            'checkout_overdue' => (clone $departures)->where('check_out', $overdueToday ? '<=' : '<', $today)
                ->orderBy('check_out')->orderBy('id'),
            'checkout_due' => (clone $departures)->where('check_out', $today)->orderBy('id'),
            'room_assignment' => (clone $arrivals)->where('check_in', $today)
                ->whereNull('assigned_room_id')->orderBy('id'),
            'new_booking' => (clone $bookings)->whereIn('lifecycle_state', ['confirmed', 'checked_in'])
                ->where('created_at', '>=', now()->subDay())->latest('created_at')->orderByDesc('id'),
        ];
        if ($overdueToday) {
            unset($queues['checkout_due']);
        }

        return $queues;
    }

    public function feed(Hotel $hotel, bool $expanded = false, ?int $userId = null): array
    {
        $queues = $this->queues($hotel);
        $notifications = [];
        $hasMore = false;
        foreach ($queues as $kind => $query) {
            $rows = $query->limit(9)->get(['id', 'reference', 'check_in', 'check_out']);
            $hasMore = $hasMore || $rows->count() > 8;
            foreach ($rows->take(8) as $booking) {
                $notifications[] = $this->notification($kind, $booking);
            }
        }

        if (!$expanded) {
            $inspections = iterator_to_array(app(OperationalActivityNotifications::class)->recentApprovedInspections($hotel, 9), false);
            $hasMore = $hasMore || count($inspections) > 8;
            array_push($notifications, ...array_slice($inspections, 0, 8));
        }
        if ($expanded) {
            $counts = [];
            foreach ($this->adminActivity($hotel, $userId, 9) as $item) {
                $kind = $item['kind'];
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                if ($counts[$kind] <= 8) {
                    $notifications[] = $item;
                } else {
                    $hasMore = true;
                }
            }
        }

        return [
            'hotelId' => (string) $hotel->id,
            'notifications' => $notifications,
            'hasMore' => $hasMore,
        ];
    }

    /** Iterate the full current alert set without the navbar display limit. */
    public function all(Hotel $hotel): iterable
    {
        foreach ($this->queues($hotel) as $kind => $query) {
            foreach ($query->select(['id', 'reference', 'check_in', 'check_out'])->lazy(200) as $booking) {
                yield $this->notification($kind, $booking);
            }
        }
        yield from $this->activity($hotel);
    }

    private function adminActivity(Hotel $hotel, ?int $userId, int $limit): iterable
    {
        yield from $this->activity($hotel, $limit);
        if ($userId !== null) {
            yield from app(StaffMessageNotifications::class)->all($hotel, $userId, $limit);
        }
    }

    private function activity(Hotel $hotel, ?int $limit = null): iterable
    {
        yield from app(BookingActivityNotifications::class)->all($hotel, $limit);
        yield from app(FinancialActivityNotifications::class)->all($hotel, $limit);
        yield from app(OperationalActivityNotifications::class)->all($hotel, $limit);
        yield from app(HousekeepingNotifications::class)->manager($hotel, $limit);
        yield from app(DailyClosingNotifications::class)->all($hotel, $limit);
    }

    public function current(Hotel $hotel, string $id): ?array
    {
        if (! preg_match('/^([a-z_]+):([1-9][0-9]*)(?::[0-9]{4}-[0-9]{2}-[0-9]{2})?$/D', $id, $parts)) {
            return null;
        }
        $query = $this->queues($hotel)[$parts[1]] ?? null;
        if (! $query) {
            return app(BookingActivityNotifications::class)->current($hotel, $id)
                ?? app(FinancialActivityNotifications::class)->current($hotel, $id)
                ?? app(OperationalActivityNotifications::class)->current($hotel, $id)
                ?? app(DailyClosingNotifications::class)->current($hotel, $id)
                ?? app(HousekeepingNotifications::class)->currentManager($hotel, $id);
        }
        $booking = $query?->where('id', $parts[2])->first(['id', 'reference', 'check_in', 'check_out']);
        if (! $booking) {
            return null;
        }
        $alert = $this->notification($parts[1], $booking);

        return $alert['id'] === $id ? $alert : null;
    }

    private function notification(string $kind, object $booking): array
    {
        $date = str_starts_with($kind, 'checkout_') ? $booking->check_out : $booking->check_in;
        return [
            'id' => $kind.':'.$booking->id.($kind === 'new_booking' ? '' : ':'.$date),
            'kind' => $kind,
            'bookingId' => (string) $booking->id,
            'reference' => $booking->reference,
            'date' => $date,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 48: read-only, admin-only operational review signals.
 * An exception is a record to investigate, NEVER evidence of employee theft.
 * Each source is bounded: no truncated hotel-wide totals.
 */
final class OperationalExceptionsController
{
    private const MAX_SOURCE = 500;

    public function __invoke(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
        ]);
        $days = (int) ($input['days'] ?? 7);
        $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $through = (new \DateTimeImmutable($today))->modify('+'.$days.' days')->format('Y-m-d');
        $items = [];

        $arrivals = $this->bounded(DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->where('lifecycle_state', 'confirmed')
            ->whereNull('assigned_room_id')
            ->where('check_in', '>=', $today)->where('check_in', '<', $through)
            ->orderBy('id')
            ->get(['id', 'reference', 'check_in', 'check_out']), 'unassigned arrivals');
        foreach ($arrivals as $row) {
            $items[] = $this->signal('unassigned_arrival', 'attention', $row->id, $row->reference,
                'Upcoming arrival has no physical room assigned.',
                'Assign a room after checking availability and readiness.', (string) $row->check_in);
        }

        $inHouse = $this->bounded(DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->where('lifecycle_state', 'checked_in')
            ->whereNull('assigned_room_id')->orderBy('id')
            ->get(['id', 'reference', 'check_in', 'check_out']), 'in-house assignments');
        foreach ($inHouse as $row) {
            $items[] = $this->signal('in_house_without_room', 'critical', $row->id, $row->reference,
                'Checked-in reservation has no assigned room in PMS.',
                'Verify the stay and reconcile the recorded assignment.', (string) $row->check_in);
        }

        // Only active, assigned stays. Checkout day is exclusive.
        $stays = $this->bounded(DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->whereNotNull('assigned_room_id')
            ->whereIn('lifecycle_state', ['confirmed', 'checked_in'])
            ->where('check_in', '<', $through)->where('check_out', '>', $today)
            ->orderBy('assigned_room_id')->orderBy('check_in')->orderBy('id')
            ->get(['id', 'reference', 'assigned_room_id', 'check_in', 'check_out']), 'assigned stays');
        $byRoom = $stays->groupBy('assigned_room_id');
        foreach ($byRoom as $roomId => $roomStays) {
            $ordered = $roomStays->values();
            for ($i = 0; $i < $ordered->count(); $i++) {
                for ($j = $i + 1; $j < $ordered->count(); $j++) {
                    $first = $ordered[$i];
                    $second = $ordered[$j];
                    if ($second->check_in >= $first->check_out) {
                        break;
                    }
                    if ($first->check_in < $second->check_out) {
                        $items[] = $this->signal('assigned_room_overlap', 'critical',
                            $second->id, $second->reference,
                            'Two active reservations overlap on assigned room #'.$roomId.'.',
                            'Inspect '.$first->reference.' and '.$second->reference.' before changing assignments.',
                            (string) max($today, $second->check_in));
                    }
                }
            }
        }

        $pending = $this->bounded(DB::table('folio_payment_records as pay')
            ->join('reservation_folios as folio', function ($join): void {
                $join->on('folio.id', '=', 'pay.folio_id')
                    ->on('folio.hotel_id', '=', 'pay.hotel_id');
            })
            ->join('reservation_records as booking', function ($join): void {
                $join->on('booking.id', '=', 'folio.reservation_record_id')
                    ->on('booking.hotel_id', '=', 'folio.hotel_id');
            })
            ->where('pay.hotel_id', $hotel->id)
            ->where('pay.status', 'pending_review')
            ->orderBy('pay.id')
            ->get(['pay.id', 'pay.amount_minor', 'pay.created_at', 'booking.id as booking_id',
                'booking.reference']), 'payment reviews');
        foreach ($pending as $payment) {
            $items[] = $this->signal('payment_pending_review', 'review',
                $payment->booking_id, $payment->reference,
                'Payment evidence #'.$payment->id.' is awaiting staff review.',
                'Review supporting evidence and the folio; this is not gateway-verified settlement.',
                (string) $payment->created_at);
        }

        // Audit trail is a separate neutral activity feed. A modification alone
        // must never be called suspicious or classified as fraud.
        $activity = $this->bounded(DB::table('reservation_booking_events as ev')
            ->join('reservation_records as booking', function ($join): void {
                $join->on('booking.id', '=', 'ev.reservation_record_id')
                    ->on('booking.hotel_id', '=', 'ev.hotel_id');
            })
            ->where('ev.hotel_id', $hotel->id)
            ->where('ev.created_at', '>=', now($hotel->timezone ?: 'Asia/Manila')->subDays($days)->utc())
            ->orderByDesc('ev.id')
            ->limit(self::MAX_SOURCE + 1)
            ->get(['ev.id', 'ev.actor_id', 'ev.event_type', 'ev.created_at',
                'booking.id as booking_id', 'booking.reference']), 'reservation activity');

        $counts = ['critical' => 0, 'attention' => 0, 'review' => 0];
        foreach ($items as $item) {
            $counts[$item['severity']]++;
        }

        return response()->json([
            'data' => [
                'hotelId' => (string) $hotel->id,
                'asOfHotelDate' => $today,
                'windowEndExclusive' => $through,
                'summary' => ['total' => count($items), ...$counts],
                'items' => $items,
                'activity' => $activity->map(fn (object $row): array => [
                    'id' => (string) $row->id,
                    'bookingId' => (string) $row->booking_id,
                    'reference' => $row->reference,
                    'actorId' => (string) $row->actor_id,
                    'eventType' => $row->event_type,
                    'createdAt' => (string) $row->created_at,
                ])->values(),
            ],
            'meta' => [
                'readOnly' => true,
                'complete' => true,
                'isFraudDetermination' => false,
                'keycardLogsConnected' => false,
                'cctvEventsConnected' => false,
                'housekeepingPhysicalOccupancyConnected' => false,
                'sourceLimit' => self::MAX_SOURCE,
                'notes' => 'Operational exceptions warrant independent review. No employee wrongdoing is inferred.',
            ],
        ]);
    }

    private function bounded(\Illuminate\Support\Collection $rows, string $source): \Illuminate\Support\Collection
    {
        if ($rows->count() > self::MAX_SOURCE) {
            throw new HttpException(422, 'Exception review source exceeds supported size: '.$source.'. No partial totals returned.');
        }
        return $rows;
    }

    private function signal(string $type, string $severity, int|string $bookingId,
        string $reference, string $reason, string $suggestion, string $recordDate): array
    {
        return [
            'id' => $type.':'.$bookingId.':'.substr(hash('sha256', $reason.$recordDate), 0, 12),
            'type' => $type, 'severity' => $severity,
            'bookingId' => (string) $bookingId, 'reference' => $reference,
            'reason' => $reason, 'suggestion' => $suggestion, 'recordDate' => $recordDate,
        ];
    }
}

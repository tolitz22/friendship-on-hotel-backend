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
            ->orderBy('id')->limit(self::MAX_SOURCE + 1)
            ->get(['id', 'reference', 'check_in', 'check_out']), 'unassigned arrivals');
        foreach ($arrivals as $row) {
            $items[] = $this->signal('unassigned_arrival', 'attention', $row->id, $row->reference,
                'Upcoming arrival has no physical room assigned.',
                'Assign a room after checking availability and readiness.', (string) $row->check_in);
        }

        $inHouse = $this->bounded(DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->where('lifecycle_state', 'checked_in')
            ->whereNull('assigned_room_id')->orderBy('id')
            ->limit(self::MAX_SOURCE + 1)
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
            ->limit(self::MAX_SOURCE + 1)->get(['id', 'reference', 'assigned_room_id', 'check_in', 'check_out']), 'assigned stays');
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
            ->orderBy('pay.id')->limit(self::MAX_SOURCE + 1)
            ->get(['pay.id', 'pay.amount_minor', 'pay.created_at', 'booking.id as booking_id',
                'booking.reference']), 'payment reviews');
        foreach ($pending as $payment) {
            $items[] = $this->signal('payment_pending_review', 'review',
                $payment->booking_id, $payment->reference,
                'Payment evidence #'.$payment->id.' is awaiting staff review.',
                'Review supporting evidence and the folio; this is not gateway-verified settlement.',
                (string) $payment->created_at);
        }

        $maintenance = $this->bounded(DB::table('maintenance_work_orders as work')
            ->join('rooms as room', function ($join): void {
                $join->on('room.id', '=', 'work.room_id')
                    ->on('room.hotel_id', '=', 'work.hotel_id');
            })
            ->leftJoin('users as technician', 'technician.id', '=', 'work.assigned_user_id')
            ->where('work.hotel_id', $hotel->id)->where('work.affects_room', true)
            ->whereIn('work.status', ['open', 'in_progress'])
            ->orderBy('work.id')->limit(self::MAX_SOURCE + 1)
            ->get(['work.id','work.room_id','work.title','work.priority','work.created_at',
                'room.number as room_number','technician.name as technician_name']), 'room-impacting maintenance');
        foreach ($maintenance as $row) {
            $items[] = [
                'id' => 'maintenance:'.$row->id,
                'type' => 'maintenance_blocker',
                'severity' => in_array($row->priority, ['high','urgent'], true) ? 'critical' : 'attention',
                'bookingId' => null, 'roomId' => (string) $row->room_id,
                'roomNumber' => (string) $row->room_number, 'ownerName' => $row->technician_name,
                'reference' => 'Room '.$row->room_number,
                'reason' => 'Unresolved room-impacting maintenance: '.$row->title,
                'suggestion' => 'Review the work order and maintain readiness safeguards until resolved.',
                'recordDate' => (string) $row->created_at,
            ];
        }

        // Operational follow-up queues do not determine fraud or change records.
        // Fetch only incomplete, hotel-scoped work and preserve accurate totals.
        $inspections = $this->bounded(DB::table('room_readiness_inspections as inspection')
            ->join('rooms as room', function ($join): void {
                $join->on('room.id', '=', 'inspection.room_id')
                    ->on('room.hotel_id', '=', 'inspection.hotel_id');
            })
            ->leftJoin('users as inspector', 'inspector.id', '=', 'inspection.inspected_by')
            ->where('inspection.hotel_id', $hotel->id)->where('inspection.status', 'submitted')
            ->orderBy('inspection.id')->limit(self::MAX_SOURCE + 1)
            ->get(['inspection.id', 'inspection.room_id', 'inspection.inspected_by',
                'inspection.created_at', 'room.number as room_number',
                'inspector.name as inspector_name']), 'room inspections awaiting review');
        foreach ($inspections as $row) {
            $items[] = [
                'id' => 'room_inspection:'.$row->id,
                'type' => 'room_inspection_review', 'severity' => 'attention',
                'bookingId' => null, 'roomId' => (string) $row->room_id,
                'roomNumber' => (string) $row->room_number,
                'reference' => 'Room '.$row->room_number,
                'ownerName' => $row->inspector_name,
                'reason' => 'Room inspection is waiting for a different authorized manager to review it.',
                'suggestion' => 'Review the submitted checklist and active readiness blockers.',
                'recordDate' => (string) $row->created_at,
            ];
        }

        $observations = $this->bounded(DB::table('occupancy_verifications as check')
            ->join('rooms as room', function ($join): void {
                $join->on('room.id', '=', 'check.room_id')
                    ->on('room.hotel_id', '=', 'check.hotel_id');
            })
            ->leftJoin('occupancy_investigations as investigation', function ($join): void {
                $join->on('investigation.verification_id', '=', 'check.id')
                    ->on('investigation.hotel_id', '=', 'check.hotel_id');
            })
            ->leftJoin('users as owner', 'owner.id', '=', 'investigation.owner_id')
            ->where('check.hotel_id', $hotel->id)
            ->where(function ($query): void {
                $query->where('check.status', 'submitted')
                    ->orWhere(function ($flagged): void {
                        $flagged->where('check.status', 'flagged')
                            ->where(function ($open): void {
                                $open->whereNull('investigation.id')
                                    ->orWhere('investigation.status', 'open');
                            });
                    });
            })
            ->orderBy('check.id')->limit(self::MAX_SOURCE + 1)
            ->get(['check.id', 'check.room_id', 'check.status', 'check.inspection_date',
                'check.created_at', 'room.number as room_number',
                'investigation.status as investigation_status',
                'owner.name as owner_name']), 'occupancy follow-ups');
        foreach ($observations as $row) {
            $flagged = $row->status === 'flagged';
            $items[] = [
                'id' => 'occupancy_check:'.$row->id,
                'type' => $flagged ? 'occupancy_investigation' : 'occupancy_review',
                'severity' => $flagged ? 'critical' : 'attention',
                'bookingId' => null, 'roomId' => (string) $row->room_id,
                'roomNumber' => (string) $row->room_number,
                'reference' => 'Room '.$row->room_number,
                'ownerName' => $row->owner_name,
                'reason' => $flagged
                    ? 'An occupancy discrepancy needs a documented investigation or follow-up.'
                    : 'A submitted physical observation is awaiting independent manager review.',
                'suggestion' => $flagged
                    ? ($row->investigation_status === 'open'
                        ? 'Continue the assigned investigation and document its findings.'
                        : 'Open an investigation and assign a manager.')
                    : 'Review the recorded observation against the saved reservation snapshot.',
                'recordDate' => (string) $row->created_at,
                'checkDate' => (string) $row->inspection_date,
            ];
        }

        $shifts = $this->bounded(DB::table('cashier_shifts as shift')
            ->leftJoin('users as cashier', 'cashier.id', '=', 'shift.cashier_id')
            ->where('shift.hotel_id', $hotel->id)->whereIn('shift.status', ['submitted', 'flagged'])
            ->orderBy('shift.id')->limit(self::MAX_SOURCE + 1)
            ->get(['shift.id', 'shift.cashier_id', 'shift.status', 'shift.submitted_at',
                'cashier.name as cashier_name']), 'submitted cashier shifts');
        foreach ($shifts as $row) {
            $items[] = [
                'id' => 'cashier_shift:'.$row->id,
                'type' => $row->status === 'flagged' ? 'cashier_shift_discrepancy' : 'cashier_shift_review',
                'severity' => $row->status === 'flagged' ? 'critical' : 'review',
                'bookingId' => null, 'roomId' => null, 'roomNumber' => null,
                'reference' => 'Cashier shift '.$row->id,
                'ownerName' => $row->cashier_name,
                'reason' => $row->status === 'flagged'
                    ? 'This shift was flagged during reconciliation and needs documented follow-up.'
                    : 'Cashier has submitted a physical cash count for independent reconciliation.',
                'suggestion' => $row->status === 'flagged'
                    ? 'Investigate the discrepancy and preserve the original shift audit records.'
                    : 'Another authorized manager must review the receipts and counted cash.',
                'recordDate' => (string) $row->submitted_at,
            ];
        }

        // Audit trail is a separate neutral activity feed. A modification alone
        // must never be called suspicious or classified as fraud.
        $activity = $this->bounded(DB::table('reservation_booking_events as ev')
            ->leftJoin('users as audit_actor','audit_actor.id','=','ev.actor_id')
            ->join('reservation_records as booking', function ($join): void {
                $join->on('booking.id', '=', 'ev.reservation_record_id')
                    ->on('booking.hotel_id', '=', 'ev.hotel_id');
            })
            ->where('ev.hotel_id', $hotel->id)
            ->where('ev.created_at', '>=', now($hotel->timezone ?: 'Asia/Manila')->subDays($days)->utc())
            ->orderByDesc('ev.id')
            ->limit(self::MAX_SOURCE + 1)
            ->get(['ev.id', 'ev.actor_id', 'audit_actor.name as actor_name', 'ev.event_type', 'ev.created_at',
                'booking.id as booking_id', 'booking.reference']), 'reservation activity');

        if (count($items) > 4000) {
            throw new HttpException(422, 'Too many exception records for synchronous review. No partial totals returned.');
        }

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
                    'actorName' => $row->actor_name,
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
            'id' => $type.':'.$bookingId.':'.substr(hash('sha256', $reason.$suggestion.$recordDate), 0, 12),
            'type' => $type, 'severity' => $severity,
            'bookingId' => (string) $bookingId, 'roomId' => null, 'roomNumber' => null,
            'ownerName' => null, 'reference' => $reference,
            'reason' => $reason, 'suggestion' => $suggestion, 'recordDate' => $recordDate,
        ];
    }
}

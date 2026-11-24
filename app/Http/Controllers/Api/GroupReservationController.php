<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReservationBookingService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GroupReservationController
{
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'in:all,confirmed,partially_checked_in,fully_checked_in,partially_checked_out,checked_out,cancelled'],
            'assignment' => ['sometimes', 'in:all,assigned,unassigned'],
        ]);

        // Counts come from the linked booking lifecycle states, rather than
        // guessing a group status from its oldest or first reservation.
        $members = DB::table('group_reservation_members as member')
            ->join('reservation_records as booking', function ($join): void {
                $join->on('booking.id', '=', 'member.reservation_record_id')
                    ->on('booking.hotel_id', '=', 'member.hotel_id');
            })
            ->where('member.hotel_id', $hotel->id)
            ->groupBy('member.group_reservation_id')
            ->select('member.group_reservation_id')
            ->selectRaw('COUNT(*) as total, MIN(booking.check_in) as check_in, MAX(booking.check_out) as check_out')
            ->selectRaw("SUM(CASE WHEN booking.lifecycle_state = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count")
            ->selectRaw("SUM(CASE WHEN booking.assigned_room_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_count")
            ->selectRaw("SUM(CASE WHEN booking.lifecycle_state = 'checked_in' THEN 1 ELSE 0 END) as in_house_count")
            ->selectRaw("SUM(CASE WHEN booking.lifecycle_state = 'checked_out' THEN 1 ELSE 0 END) as departed_count")
            ->selectRaw("SUM(CASE WHEN booking.lifecycle_state = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count");

        // Hotel-wide operational KPIs must not be inferred from the
        // current 20-row page, or altered by the list search/filter.
        $hotelToday = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $upcomingCutoff = now($hotel->timezone ?: 'Asia/Manila')
            ->addDays(7)->toDateString();
        $statusExpression = $this->groupStatusSql();
        $summary = DB::table('group_reservations as groups')
            ->leftJoinSub(clone $members, 'stats', function ($join): void {
                $join->on('stats.group_reservation_id', '=', 'groups.id');
            })
            ->where('groups.hotel_id', $hotel->id)
            ->selectRaw('COUNT(*) as group_total')
            ->selectRaw("SUM(CASE WHEN stats.check_in BETWEEN ? AND ?
                 AND (".$statusExpression.") = 'confirmed'
                 THEN 1 ELSE 0 END) as upcoming_arrivals", [$hotelToday, $upcomingCutoff])
            ->selectRaw("SUM(CASE WHEN (".$statusExpression.") IN
                 ('partially_checked_in','fully_checked_in','partially_checked_out')
                 THEN 1 ELSE 0 END) as in_house_groups")
            ->selectRaw("SUM(CASE WHEN (".$statusExpression.") IN
                 ('partially_checked_in','partially_checked_out')
                 THEN 1 ELSE 0 END) as attention_groups")
            ->first();

        $query = DB::table('group_reservations as groups')
            ->leftJoinSub($members, 'stats', function ($join): void {
                $join->on('stats.group_reservation_id', '=', 'groups.id');
            })
            ->where('groups.hotel_id', $hotel->id);

        $term = trim($input['search'] ?? '');
        if ($term !== '') {
            $parts = preg_split('/\\s+/', $term) ?: [];
            $query->where(function ($search) use ($term, $parts, $hotel): void {
                $search->where('groups.name', 'like', '%'.$term.'%')
                    ->orWhere('groups.reference', 'like', '%'.$term.'%')
                    ->orWhere('groups.contact_name', 'like', '%'.$term.'%')
                    ->orWhereExists(function ($linked) use ($term, $parts, $hotel): void {
                        $linked->selectRaw('1')->from('group_reservation_members as gm')
                            ->join('reservation_records as booking', function ($join): void {
                                $join->on('booking.id', '=', 'gm.reservation_record_id')
                                    ->on('booking.hotel_id', '=', 'gm.hotel_id');
                            })
                            ->leftJoin('guests as guest', function ($join): void {
                                $join->on('guest.id', '=', 'booking.guest_id')
                                    ->on('guest.hotel_id', '=', 'booking.hotel_id');
                            })
                            ->leftJoin('rooms as room', function ($join): void {
                                $join->on('room.id', '=', 'booking.assigned_room_id')
                                    ->on('room.hotel_id', '=', 'booking.hotel_id');
                            })
                            ->whereColumn('gm.group_reservation_id', 'groups.id')
                            ->where('gm.hotel_id', $hotel->id)
                            ->where(function ($match) use ($term, $parts): void {
                                $match->where('booking.reference', 'like', '%'.$term.'%')
                                    ->orWhere('room.number', 'like', '%'.$term.'%')
                                    ->orWhere('guest.first_name', 'like', '%'.$term.'%')
                                    ->orWhere('guest.last_name', 'like', '%'.$term.'%');
                                if (count($parts) >= 2) {
                                    $first = $parts[0];
                                    $last = implode(' ', array_slice($parts, 1));
                                    $match->orWhere(function ($name) use ($first, $last): void {
                                        $name->where('guest.first_name', 'like', '%'.$first.'%')
                                            ->where('guest.last_name', 'like', '%'.$last.'%');
                                    });
                                }
                            });
                    });
            });
        }
        if (($input['assignment'] ?? 'all') === 'assigned') {
            $query->whereRaw('COALESCE(stats.assigned_count, 0) = COALESCE(stats.total, 0)');
        } elseif (($input['assignment'] ?? 'all') === 'unassigned') {
            $query->whereRaw('COALESCE(stats.assigned_count, 0) < COALESCE(stats.total, 0)');
        }

        $state = $input['status'] ?? 'all';
        if ($state !== 'all') {
            $statusSql = $this->groupStatusSql();
            $query->whereRaw('('.$statusSql.') = ?', [$state]);
        }

        $page = $query->orderByDesc('groups.id')
            ->paginate(20, [
                'groups.id', 'groups.reference', 'groups.name',
                'groups.contact_name', 'groups.created_at',
                'stats.total', 'stats.check_in', 'stats.check_out',
                'stats.assigned_count',
                'stats.confirmed_count', 'stats.in_house_count',
                'stats.departed_count', 'stats.cancelled_count',
            ], 'page', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(function (object $row): array {
                $counts = [
                    'confirmed' => (int) $row->confirmed_count,
                    'checkedIn' => (int) $row->in_house_count,
                    'checkedOut' => (int) $row->departed_count,
                    'cancelled' => (int) $row->cancelled_count,
                ];
                return [
                    'id' => (string) $row->id, 'reference' => $row->reference,
                    'name' => $row->name, 'contactName' => $row->contact_name,
                    'roomCount' => (int) $row->total,
                    'assignedCount' => (int) $row->assigned_count,
                    'checkIn' => $row->check_in, 'checkOut' => $row->check_out,
                    'createdAt' => (string) $row->created_at,
                    'status' => $this->groupStatus((int) $row->total, $counts),
                    'statusCounts' => $counts,
                ];
            })->values(),
            'meta' => [
                'page' => $page->currentPage(), 'total' => $page->total(),
                'totalPages' => $page->lastPage(), 'perPage' => 20,
                'summary' => [
                    'totalGroups' => (int) ($summary?->group_total ?? 0),
                    'upcomingArrivals' => (int) ($summary?->upcoming_arrivals ?? 0),
                    'inHouseGroups' => (int) ($summary?->in_house_groups ?? 0),
                    'attentionGroups' => (int) ($summary?->attention_groups ?? 0),
                    'upcomingWindowStart' => $hotelToday,
                    'upcomingWindowEnd' => $upcomingCutoff,
                ],
            ],
        ]);
    }

    private function groupStatus(int $total, array $counts): string
    {
        if ($total === 0) return 'confirmed';
        $cancelled = $counts['cancelled'];
        $active = $total - $cancelled;
        if ($cancelled === $total) return 'cancelled';
        if ($active <= 0) return 'cancelled';
        if ($counts['checkedOut'] === $active) return 'checked_out';
        if ($counts['checkedOut'] > 0) return 'partially_checked_out';
        if ($counts['checkedIn'] === $active) return 'fully_checked_in';
        if ($counts['checkedIn'] > 0) return 'partially_checked_in';
        return 'confirmed';
    }

    private function groupStatusSql(): string
    {
        return "CASE
            WHEN COALESCE(stats.total,0) = COALESCE(stats.cancelled_count,0)
                 AND COALESCE(stats.total,0) > 0 THEN 'cancelled'
            WHEN COALESCE(stats.departed_count,0) = COALESCE(stats.total,0) - COALESCE(stats.cancelled_count,0)
                 AND COALESCE(stats.total,0) > COALESCE(stats.cancelled_count,0) THEN 'checked_out'
            WHEN COALESCE(stats.departed_count,0) > 0 THEN 'partially_checked_out'
            WHEN COALESCE(stats.in_house_count,0) = COALESCE(stats.total,0) - COALESCE(stats.cancelled_count,0)
                 AND COALESCE(stats.total,0) > COALESCE(stats.cancelled_count,0) THEN 'fully_checked_in'
            WHEN COALESCE(stats.in_house_count,0) > 0 THEN 'partially_checked_in'
            ELSE 'confirmed' END";
    }

    public function show(Hotel $hotel, string $group): JsonResponse
    {
        return response()->json(['data' => $this->present((int) $hotel->id, (int) $group, false)]);
    }

    public function store(Request $request, Hotel $hotel, ReservationBookingService $bookings): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:100'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'contactName' => ['nullable', 'string', 'max:120'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
            'rooms' => ['required', 'array', 'min:2', 'max:10'],
            'allotmentId' => ['sometimes', 'integer', 'min:1'],
            'rooms.*.guestId' => ['required', 'integer', 'min:1'],
            'rooms.*.roomTypeId' => ['required', 'integer', 'min:1'],
            'rooms.*.ratePlanId' => ['nullable', 'integer', 'min:1'],
            'rooms.*.dynamicQuoteToken' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'rooms.*.corporateCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'rooms.*.promoCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ])->validate();

        $dynamicCount = 0;
        foreach ($data['rooms'] as $room) {
            $hasToken = isset($room['dynamicQuoteToken']);
            abort_if(isset($room['corporateCode'], $room['promoCode']), 422, 'Choose one eligibility code per room.');
            abort_if((isset($room['corporateCode']) || isset($room['promoCode'])) && ! $hasToken, 422, 'Review the dynamic quote for every eligibility code.');
            abort_if($hasToken && ! isset($room['ratePlanId']), 422, 'Select a rate plan for every dynamic quote.');
            $dynamicCount += (int) $hasToken;
        }
        abort_if($dynamicCount > 0 && $dynamicCount !== count($data['rooms']), 422, 'Review dynamic pricing for every room in the group.');

        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw new HttpException(422, 'Group name is required.');
        }
        $data['contactName'] = isset($data['contactName']) ? trim($data['contactName']) : null;

        $fingerprint = hash('sha256', json_encode([
            'name' => $data['name'], 'contactName' => $data['contactName'],
            'checkIn' => $data['checkIn'], 'checkOut' => $data['checkOut'],
            'allotmentId' => isset($data['allotmentId']) ? (int) $data['allotmentId'] : null,
            'rooms' => array_map(static fn (array $room): array => [
                'guestId' => (int) $room['guestId'], 'roomTypeId' => (int) $room['roomTypeId'],
                'ratePlanId' => isset($room['ratePlanId']) ? (int) $room['ratePlanId'] : null,
                ... (isset($room['dynamicQuoteToken']) ? [
                    'dynamicQuoteToken' => $room['dynamicQuoteToken'],
                    'corporateCode' => $room['corporateCode'] ?? null,
                    'promoCode' => $room['promoCode'] ?? null,
                ] : []),
            ], $data['rooms']),
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($hotel, $data, $fingerprint, $request, $bookings): array {
            // Serialize concurrent submissions of one group key, including a retry
            // before the original write has committed.
            DB::table('hotels')->where('id', $hotel->id)->lockForUpdate()->first();
            $existing = DB::table('group_reservations')->where('hotel_id', $hotel->id)
                ->where('idempotency_key', $data['idempotencyKey'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_sha256, $fingerprint)) {
                    throw new HttpException(409, 'Group request key was already used with different details.');
                }
                return $this->present((int) $hotel->id, (int) $existing->id, true);
            }

            // Acquire type locks in deterministic order, before booking any room.
            // Each individual booking also uses its original inventory checks.
            $types = collect($data['rooms'])->pluck('roomTypeId')->unique()->sort()->values();
            foreach ($types as $typeId) {
                $type = DB::table('room_types')->where('hotel_id', $hotel->id)
                    ->where('id', $typeId)->lockForUpdate()->first();
                abort_unless($type !== null, 404);
            }

            // Convert only a matching, unexpired hold. Releasing its
            // inventory consumption and creating all bookings are atomic.
            $allotment = null;
            if (isset($data['allotmentId'])) {
                $allotment = DB::table('group_allotments')
                    ->where('hotel_id', $hotel->id)
                    ->where('id', $data['allotmentId'])->lockForUpdate()->first();
                abort_unless($allotment !== null, 404);
                $matchingType = collect($data['rooms'])->every(
                    fn (array $room): bool => (int) $room['roomTypeId'] === (int) $allotment->room_type_id
                );
                if ($allotment->status !== 'held' || (string) $allotment->expires_at <= now()->toDateTimeString()
                    || $allotment->check_in !== $data['checkIn']
                    || $allotment->check_out !== $data['checkOut']
                    || (int) $allotment->quantity !== count($data['rooms'])
                    || ! $matchingType) {
                    throw new HttpException(409, 'The allotment is expired, released or does not match the selected rooms and stay.');
                }
                DB::table('group_allotments')->where('id', $allotment->id)
                    ->update(['status' => 'converted', 'updated_at' => now()]);
            }

            $groupId = DB::table('group_reservations')->insertGetId([
                'hotel_id' => $hotel->id, 'reference' => 'GRP-'.Str::upper(Str::random(14)),
                'name' => $data['name'], 'contact_name' => $data['contactName'],
                'idempotency_key' => $data['idempotencyKey'],
                'payload_sha256' => $fingerprint, 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($allotment !== null) {
                DB::table('group_allotments')->where('id', $allotment->id)
                    ->update(['converted_group_id' => $groupId, 'updated_at' => now()]);
            }
            foreach ($data['rooms'] as $index => $room) {
                // Child booking idempotency is separate from the group request.
                // No child booking survives if any later member fails.
                $booking = $bookings->create($hotel, (int) $request->user()->id, [
                    'idempotencyKey' => 'group-'.$groupId.'-room-'.($index + 1),
                    'guestId' => (int) $room['guestId'],
                    'roomTypeId' => (int) $room['roomTypeId'],
                    'checkIn' => $data['checkIn'], 'checkOut' => $data['checkOut'],
                    ... (isset($room['ratePlanId']) ? ['ratePlanId' => (int) $room['ratePlanId']] : []),
                    ... (isset($room['dynamicQuoteToken']) ? [
                        'dynamicQuoteToken' => $room['dynamicQuoteToken'],
                        'corporateCode' => $room['corporateCode'] ?? null,
                        'promoCode' => $room['promoCode'] ?? null,
                    ] : []),
                ]);
                DB::table('group_reservation_members')->insert([
                    'hotel_id' => $hotel->id, 'group_reservation_id' => $groupId,
                    'reservation_record_id' => (int) $booking['id'],
                    'position' => $index + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $this->present((int) $hotel->id, $groupId, false);
        }, 3);

        return response()->json(['data' => $result], $result['idempotentReplay'] ? 200 : 201);
    }

    private function present(int $hotelId, int $groupId, bool $replayed): array
    {
        $group = DB::table('group_reservations')->where('hotel_id', $hotelId)
            ->where('id', $groupId)->first();
        abort_unless($group !== null, 404);
        $members = DB::table('group_reservation_members as gm')
            ->join('reservation_records as booking', function ($join): void {
                $join->on('booking.id', '=', 'gm.reservation_record_id')
                    ->on('booking.hotel_id', '=', 'gm.hotel_id');
            })
            ->join('room_types as type', function ($join): void {
                $join->on('type.id', '=', 'booking.room_type_id')
                    ->on('type.hotel_id', '=', 'booking.hotel_id');
            })
            ->leftJoin('guests as guest', function ($join): void {
                $join->on('guest.id', '=', 'booking.guest_id')
                    ->on('guest.hotel_id', '=', 'booking.hotel_id');
            })
            ->leftJoin('rooms as room', function ($join): void {
                $join->on('room.id', '=', 'booking.assigned_room_id')
                    ->on('room.hotel_id', '=', 'booking.hotel_id');
            })
            ->where('gm.hotel_id', $hotelId)->where('gm.group_reservation_id', $groupId)
            ->orderBy('gm.position')
            ->get(['booking.id', 'booking.reference', 'booking.check_in', 'booking.check_out',
                'booking.lifecycle_state', 'booking.assigned_room_id',
                'type.name as type_name', 'room.number as room_number',
                'guest.first_name', 'guest.last_name']);

        $actorName = DB::table('users')->where('id', $group->created_by)->value('name');
        $eventRows = DB::table('reservation_booking_events as event')
            ->join('group_reservation_members as gm', function ($join): void {
                $join->on('gm.reservation_record_id', '=', 'event.reservation_record_id')
                    ->on('gm.hotel_id', '=', 'event.hotel_id');
            })
            ->join('reservation_records as booking', function ($join): void {
                $join->on('booking.id', '=', 'event.reservation_record_id')
                    ->on('booking.hotel_id', '=', 'event.hotel_id');
            })
            ->leftJoin('guests as guest', function ($join): void {
                $join->on('guest.id', '=', 'booking.guest_id')
                    ->on('guest.hotel_id', '=', 'booking.hotel_id');
            })
            ->leftJoin('users as actor', 'actor.id', '=', 'event.actor_id')
            ->where('event.hotel_id', $hotelId)
            ->where('gm.group_reservation_id', $groupId)
            ->orderByDesc('event.created_at')->orderByDesc('event.id')
            ->limit(51)
            ->get(['event.id', 'event.event_type', 'event.old_state',
                'event.new_state', 'event.created_at', 'event.actor_id',
                'actor.name as actor_name', 'booking.reference as booking_reference',
                'guest.first_name', 'guest.last_name']);
        $hasMoreEvents = $eventRows->count() > 50;
        $activity = $eventRows->take(50)->map(static fn (object $event): array => [
            'id' => (string) $event->id,
            'action' => $event->event_type,
            'before' => $event->old_state,
            'after' => $event->new_state,
            'actorId' => (string) $event->actor_id,
            'actorName' => $event->actor_name,
            'bookingReference' => $event->booking_reference,
            'guestName' => $event->first_name === null ? null
                : trim($event->first_name.' '.$event->last_name),
            'at' => (string) $event->created_at,
        ])->values()->all();

        // Supplement the booking audit with separately attributable operational
        // events. No payment details are exposed through reservation.read;
        // finance history remains protected by finance.read permissions.
        $memberIds = $members->pluck('id')->all();
        $relatedEvents = collect();
        if ($memberIds !== []) {
            $housekeeping = DB::table('housekeeping_task_events as event')
                ->join('reservation_turnover_links as link', function ($join): void {
                    $join->on('link.housekeeping_task_id', '=', 'event.task_id')
                        ->on('link.hotel_id', '=', 'event.hotel_id');
                })
                ->leftJoin('users as actor', 'actor.id', '=', 'event.actor_id')
                ->where('event.hotel_id', $hotelId)
                ->whereIn('link.reservation_record_id', $memberIds)
                ->orderByDesc('event.id')->limit(50)
                ->get(['event.id', 'event.action', 'event.created_at',
                    'event.actor_id', 'actor.name as actor_name',
                    'link.reservation_record_id as booking_id', 'event.task_id']);
            foreach ($housekeeping as $event) {
                $relatedEvents->push([
                    'id' => 'cleaning-'.(string) $event->id,
                    'source' => 'housekeeping', 'action' => (string) $event->action,
                    'actorName' => $event->actor_name,
                    'at' => (string) $event->created_at,
                    'bookingId' => (string) $event->booking_id,
                    'recordId' => (string) $event->task_id,
                ]);
            }
            $approvals = DB::table('manager_approval_events as event')
                ->join('manager_approval_requests as approval', function ($join): void {
                    $join->on('approval.id', '=', 'event.approval_request_id')
                        ->on('approval.hotel_id', '=', 'event.hotel_id');
                })
                ->leftJoin('users as actor', 'actor.id', '=', 'event.actor_id')
                ->where('event.hotel_id', $hotelId)
                ->whereIn('approval.reservation_record_id', $memberIds)
                ->orderByDesc('event.id')->limit(50)
                ->get(['event.id', 'event.event', 'event.created_at',
                    'actor.name as actor_name',
                    'approval.reservation_record_id as booking_id',
                    'event.approval_request_id']);
            foreach ($approvals as $event) {
                $relatedEvents->push([
                    'id' => 'approval-'.(string) $event->id,
                    'source' => 'manager_approval', 'action' => (string) $event->event,
                    'actorName' => $event->actor_name,
                    'at' => (string) $event->created_at,
                    'bookingId' => (string) $event->booking_id,
                    'recordId' => (string) $event->approval_request_id,
                ]);
            }
        }
        $assignedRoomIds = $members->pluck('assigned_room_id')->filter()->unique()->values()->all();
        if ($assignedRoomIds !== []) {
            // This identifies events on currently assigned physical rooms,
            // not an assertion that every such inspection was group-specific.
            $inspections = DB::table('room_readiness_events as event')
                ->leftJoin('users as actor', 'actor.id', '=', 'event.actor_id')
                ->where('event.hotel_id', $hotelId)
                ->whereIn('event.room_id', $assignedRoomIds)
                ->where('event.created_at', '>=', $group->created_at)
                ->orderByDesc('event.id')->limit(50)
                ->get(['event.id', 'event.action', 'event.created_at',
                    'actor.name as actor_name', 'event.room_id', 'event.inspection_id']);
            foreach ($inspections as $event) {
                $relatedEvents->push([
                    'id' => 'inspection-'.(string) $event->id,
                    'source' => 'room_inspection', 'action' => (string) $event->action,
                    'actorName' => $event->actor_name,
                    'at' => (string) $event->created_at,
                    'bookingId' => null,
                    'recordId' => (string) $event->inspection_id,
                    'roomId' => (string) $event->room_id,
                ]);
            }
        }
        $relatedEvents = $relatedEvents->sortByDesc('at')->take(50)->values();

        // Operational indicators are derived from the existing authoritative
        // readiness service and checkout-to-cleaning links, never guessed from
        // booking status or the mere existence of a housekeeping task.
        $property = Hotel::findOrFail($hotelId);
        $assigned = $members->filter(fn (object $member): bool => $member->assigned_room_id !== null);
        $advisoryReadyCount = $assigned->filter(fn (object $member): bool =>
            app(\App\Domain\Reservations\RoomReadinessService::class)
                ->show($property, (int) $member->assigned_room_id)['advisoryReady']
        )->count();
        $departedIds = $members->filter(fn (object $member): bool =>
            $member->lifecycle_state === 'checked_out')->pluck('id')->all();
        $linkedCleaning = $departedIds === [] ? collect() :
            DB::table('reservation_turnover_links as turnover')
                ->join('housekeeping_tasks as cleaning', function ($join): void {
                    $join->on('cleaning.id', '=', 'turnover.housekeeping_task_id')
                        ->on('cleaning.hotel_id', '=', 'turnover.hotel_id');
                })
                ->where('turnover.hotel_id', $hotelId)
                ->whereIn('turnover.reservation_record_id', $departedIds)
                ->orderByDesc('turnover.id')
                ->get(['turnover.reservation_record_id', 'cleaning.status', 'cleaning.assigned_to'])
                ->unique('reservation_record_id');
        $cleaned = $linkedCleaning->where('status', 'done')->count();
        $pendingCleaning = $linkedCleaning->where('status', '!=', 'done')->count();
        $unassignedCleaning = $linkedCleaning->filter(static fn (object $task): bool =>
            $task->status !== 'done' && ($task->assigned_to === null || trim((string) $task->assigned_to) === ''))->count();

        // Consolidated display is informational only: charges, payments, taxes,
        // refunds and receipts remain in their own reservation folios.
        return [
            'id' => (string) $group->id, 'hotelId' => (string) $hotelId,
            'reference' => $group->reference, 'name' => $group->name,
            'contactName' => $group->contact_name,
            'createdAt' => (string) $group->created_at,
            'createdByName' => $actorName,
            'activity' => $activity, 'activityHasMore' => $hasMoreEvents,
            'relatedOperationalEvents' => $relatedEvents->all(),
            'relatedOperationalEventsLimited' => $relatedEvents->count() >= 50,
            'roomCount' => $members->count(),
            'operations' => [
                'assignedCount' => $assigned->count(),
                'readinessAdvisoryReadyCount' => $advisoryReadyCount,
                'readinessCheckedCount' => $assigned->count(),
                'checkedInOrOutCount' => $members->filter(fn (object $member): bool =>
                    in_array($member->lifecycle_state, ['checked_in', 'checked_out'], true))->count(),
                'checkedOutCount' => count($departedIds),
                'checkoutCleaningCompleteCount' => $cleaned,
                'checkoutCleaningPendingCount' => $pendingCleaning,
                'checkoutCleaningUnassignedCount' => $unassignedCleaning,
                'checkoutCleaningNoTaskCount' => count($departedIds) - $linkedCleaning->count(),
                'readinessIsAdvisoryOnly' => true,
            ],
            'checkIn' => $members->min('check_in'),
            'checkOut' => $members->max('check_out'),
            'rooms' => $members->map(fn (object $row): array => [
                'bookingId' => (string) $row->id, 'reference' => $row->reference,
                'guestName' => $row->first_name === null ? null
                    : trim($row->first_name.' '.$row->last_name),
                'roomTypeName' => $row->type_name, 'roomNumber' => $row->room_number,
                'roomId' => $row->assigned_room_id === null ? null : (string) $row->assigned_room_id,
                'checkIn' => (string) $row->check_in, 'checkOut' => (string) $row->check_out,
                'status' => $row->lifecycle_state,
            ])->values(),
            'billingMode' => 'individual_folios',
            'groupBillingTransfersEnabled' => false,
            'idempotentReplay' => $replayed,
        ];
    }
}

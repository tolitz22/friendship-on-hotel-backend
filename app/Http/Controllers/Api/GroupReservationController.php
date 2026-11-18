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
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = DB::table('group_reservations')
            ->where('hotel_id', $hotel->id)->orderByDesc('id')
            ->paginate(20, ['id', 'reference', 'name', 'contact_name', 'created_at'], 'page', (int) ($input['page'] ?? 1));
        $ids = $page->getCollection()->pluck('id');
        $counts = DB::table('group_reservation_members')->where('hotel_id', $hotel->id)
            ->whereIn('group_reservation_id', $ids)
            ->selectRaw('group_reservation_id, COUNT(*) as total')
            ->groupBy('group_reservation_id')->pluck('total', 'group_reservation_id');
        return response()->json([
            'data' => $page->getCollection()->map(fn (object $row): array => [
                'id' => (string) $row->id, 'reference' => $row->reference, 'name' => $row->name,
                'contactName' => $row->contact_name, 'roomCount' => (int) ($counts[$row->id] ?? 0),
                'createdAt' => (string) $row->created_at,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'total' => $page->total(),
                'totalPages' => $page->lastPage(), 'perPage' => 20],
        ]);
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
        ])->validate();

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
                if ($allotment->status !== 'held' || \Carbon\Carbon::parse($allotment->expires_at, 'UTC')->lessThanOrEqualTo(now())
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
                'booking.lifecycle_state', 'type.name as type_name', 'room.number as room_number',
                'guest.first_name', 'guest.last_name']);

        // Consolidated display is informational only: charges, payments, taxes,
        // refunds and receipts remain in their own reservation folios.
        return [
            'id' => (string) $group->id, 'hotelId' => (string) $hotelId,
            'reference' => $group->reference, 'name' => $group->name,
            'contactName' => $group->contact_name,
            'createdAt' => (string) $group->created_at,
            'roomCount' => $members->count(),
            'rooms' => $members->map(fn (object $row): array => [
                'bookingId' => (string) $row->id, 'reference' => $row->reference,
                'guestName' => $row->first_name === null ? null
                    : trim($row->first_name.' '.$row->last_name),
                'roomTypeName' => $row->type_name, 'roomNumber' => $row->room_number,
                'checkIn' => (string) $row->check_in, 'checkOut' => (string) $row->check_out,
                'status' => $row->lifecycle_state,
            ])->values(),
            'billingMode' => 'individual_folios',
            'groupBillingTransfersEnabled' => false,
            'idempotentReplay' => $replayed,
        ];
    }
}

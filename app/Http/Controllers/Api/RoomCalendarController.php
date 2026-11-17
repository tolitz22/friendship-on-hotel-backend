<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, hotel-scoped room-by-day booking inventory.
 * Departure date is exclusive; unassigned bookings are NOT room occupancy.
 */
final class RoomCalendarController
{
    public function __invoke(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after:start'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'floor' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'room_type_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:32'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $input['start']);
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $input['end']);
        abort_unless($from && $until && $from->diffInDays($until) <= 31, 422,
            'A calendar request must cover 1 to 31 nights.');

        $rooms = DB::table('rooms as rm')
            ->join('room_types as rt', function ($join): void {
                $join->on('rt.id', '=', 'rm.room_type_id')
                    ->on('rt.hotel_id', '=', 'rm.hotel_id');
            })
            ->where('rm.hotel_id', $hotel->id);
        if (isset($input['floor'])) $rooms->where('rm.floor_number', $input['floor']);
        if (isset($input['room_type_id'])) $rooms->where('rm.room_type_id', $input['room_type_id']);
        if (isset($input['search']) && trim($input['search']) !== '') {
            $rooms->where('rm.number', 'like', '%'.addcslashes(trim($input['search']), '%_\\').'%');
        }
        $page = $rooms->orderBy('rm.number')->orderBy('rm.id')
            ->select('rm.id', 'rm.number', 'rm.floor_number', 'rm.room_type_id', 'rt.name as room_type_name')
            ->paginate((int) ($input['per_page'] ?? 30));

        $roomIds = $page->getCollection()->pluck('id')->all();
        $bookings = count($roomIds) ? DB::table('reservation_records as rr')
            ->leftJoin('guests as guest', function ($join): void {
                $join->on('guest.id', '=', 'rr.guest_id')->on('guest.hotel_id', '=', 'rr.hotel_id');
            })
            ->where('rr.hotel_id', $hotel->id)
            ->whereIn('rr.assigned_room_id', $roomIds)
            ->where('rr.check_in', '<', $input['end'])
            ->where('rr.check_out', '>', $input['start'])
            ->where(function ($q): void {
                $q->whereNull('rr.lifecycle_state')
                    ->orWhereNotIn('rr.lifecycle_state', ['cancelled', 'no_show']);
            })
            ->orderBy('rr.check_in')->get([
                'rr.id', 'rr.reference', 'rr.assigned_room_id', 'rr.check_in', 'rr.check_out', 'rr.lifecycle_state',
                'guest.first_name as guest_first_name', 'guest.last_name as guest_last_name',
            ]) : collect();

        $blocks = count($roomIds) ? DB::table('room_inventory_blocks')
            ->where('hotel_id', $hotel->id)->whereIn('room_id', $roomIds)
            ->where('is_active', true)->where('check_in', '<', $input['end'])
            ->where('check_out', '>', $input['start'])->orderBy('check_in')
            ->get(['id', 'room_id', 'check_in', 'check_out', 'reason']) : collect();

        $unassigned = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->whereNull('assigned_room_id')
            ->where('check_in', '<', $input['end'])->where('check_out', '>', $input['start'])
            ->where(function ($q): void {
                $q->whereNull('lifecycle_state')
                    ->orWhereNotIn('lifecycle_state', ['cancelled', 'checked_out', 'no_show']);
            })->count();

        return response()->json([
            'data' => [
                'hotelId' => (string) $hotel->id,
                'start' => $input['start'], 'end' => $input['end'],
                'rooms' => $page->getCollection()->map(fn ($r): array => [
                    'id' => (string) $r->id,
                    'number' => $r->number,
                    'floor' => $r->floor_number === null ? null : (int) $r->floor_number,
                    'roomTypeId' => (string) $r->room_type_id,
                    'roomTypeName' => $r->room_type_name,
                ])->values(),
                'bookings' => $bookings->map(fn ($r): array => [
                    'id' => (string) $r->id, 'reference' => $r->reference,
                    'roomId' => (string) $r->assigned_room_id,
                    'checkIn' => $r->check_in, 'checkOut' => $r->check_out,
                    'status' => $r->lifecycle_state,
                    'guestName' => $r->guest_first_name !== null
                        ? trim($r->guest_first_name.' '.($r->guest_last_name ?? '')) : null,
                ])->values(),
                'blocks' => $blocks->map(fn ($r): array => [
                    'id' => (string) $r->id, 'roomId' => (string) $r->room_id,
                    'checkIn' => $r->check_in, 'checkOut' => $r->check_out,
                    'reason' => $r->reason,
                ])->values(),
                'unassignedBookings' => $unassigned,
            ],
            'meta' => [
                'page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage(),
                'readOnly' => true,
                'checkoutDateExclusive' => true,
                'isActualPhysicalOccupancyEvidence' => false,
            ],
        ]);
    }
}

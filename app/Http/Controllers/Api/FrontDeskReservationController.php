<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReservationAmendmentService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FrontDeskReservationController
{
    public function manifest(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['sometimes', 'in:arrivals,departures,in_house'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $date = $input['date'];
        $kind = $input['kind'] ?? 'arrivals';

        $query = DB::table('reservation_records as r')
            ->leftJoin('rooms as rm', function ($join): void {
                $join->on('rm.id', '=', 'r.assigned_room_id')
                    ->on('rm.hotel_id', '=', 'r.hotel_id');
            })
            ->leftJoin('guests as g', function ($join): void {
                $join->on('g.id', '=', 'r.guest_id')->on('g.hotel_id', '=', 'r.hotel_id');
            })
            ->where('r.hotel_id', $hotel->id);
        if ($kind === 'arrivals') {
            $query->where('r.check_in', $date)->where('r.lifecycle_state', 'confirmed');
        } elseif ($kind === 'departures') {
            $query->where('r.check_out', $date)->where('r.lifecycle_state', 'checked_in');
        } else {
            $query->where('r.check_in', '<=', $date)
                ->where('r.check_out', '>', $date)->where('r.lifecycle_state', 'checked_in');
        }

        $page = $query->orderBy('r.id')->select(
            'r.id', 'r.reference', 'r.check_in', 'r.check_out', 'r.lifecycle_state',
            'r.assigned_room_id', 'rm.number as room_number',
            'r.guest_id', 'g.first_name', 'g.last_name'
        )->paginate((int) ($input['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (object $record): array => [
                'id' => (string) $record->id,
                'reference' => $record->reference,
                'guestId' => $record->guest_id === null ? null : (string) $record->guest_id,
                'guestName' => $record->first_name === null ? null : trim($record->first_name.' '.$record->last_name),
                'assignedRoomId' => $record->assigned_room_id === null ? null : (string) $record->assigned_room_id,
                'roomNumber' => $record->room_number,
                'checkIn' => $record->check_in,
                'checkOut' => $record->check_out,
                'status' => $record->lifecycle_state,
            ])->values(),
            'meta' => [
                'date' => $date, 'kind' => $kind,
                'page' => $page->currentPage(), 'total' => $page->total(),
                'perPage' => $page->perPage(), 'totalPages' => $page->lastPage(),
                'determinesHousekeepingReadiness' => false,
            ],
        ]);
    }

    public function amend(Request $request, Hotel $hotel, string $booking, ReservationAmendmentService $amendments): JsonResponse
    {
        $data = $request->validate([
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
        ]);
        return response()->json(['data' => $amendments->amend(
            $hotel, (int) $request->user()->id, (int) $booking, $data
        )]);
    }
}

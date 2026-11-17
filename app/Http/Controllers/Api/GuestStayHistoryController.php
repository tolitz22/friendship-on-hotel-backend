<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GuestStayHistoryController
{
    public function __invoke(Request $request, Hotel $hotel, string $guest): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:50'],
        ]);
        abort_unless($hotel->guests()->whereKey($guest)->exists(),404);
        $page = DB::table('reservation_records as r')
            ->leftJoin('room_types as t', function ($join): void {
                $join->on('t.id','=','r.room_type_id')->on('t.hotel_id','=','r.hotel_id');
            })
            ->leftJoin('rooms as room', function ($join): void {
                $join->on('room.id','=','r.assigned_room_id')->on('room.hotel_id','=','r.hotel_id');
            })
            ->where('r.hotel_id',$hotel->id)->where('r.guest_id',$guest)
            ->orderByDesc('r.check_in')->orderByDesc('r.id')
            ->select(['r.id','r.reference','r.check_in','r.check_out','r.lifecycle_state',
                't.name as room_type_name','room.number as room_number'])
            ->paginate((int) ($filters['per_page'] ?? 10));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row): array => [
                'id' => (string) $row->id,
                'reference' => $row->reference,
                'checkIn' => $row->check_in,
                'checkOut' => $row->check_out,
                'status' => $row->lifecycle_state,
                'roomTypeName' => $row->room_type_name,
                'roomNumber' => $row->room_number,
            ])->values(),
            'meta' => [
                'hotelId' => (string) $hotel->id,
                'guestId' => (string) $guest,
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'totalPages' => $page->lastPage(),
                'excludesUnverifiedLifetimeSpend' => true,
            ],
        ]);
    }
}

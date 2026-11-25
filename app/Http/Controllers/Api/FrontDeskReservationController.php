<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReservationAmendmentService;
use App\Domain\Reservations\ReservationExtensionService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FrontDeskReservationController
{
    public const CHECKOUT_CUTOFF = '12:00';

    public function manifest(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['sometimes', 'in:arrivals,departures,in_house,overdue'],
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
        } elseif ($kind === 'overdue') {
            $clock = now($hotel->timezone ?: 'Asia/Manila');
            $query->where('r.lifecycle_state', 'checked_in')
                ->where('r.check_out', $clock->format('H:i') >= self::CHECKOUT_CUTOFF ? '<=' : '<', $clock->toDateString())
                ->orderBy('r.check_out');
            $query->selectSub(DB::table('reservation_booking_events as event')
                ->whereColumn('event.hotel_id', 'r.hotel_id')
                ->whereColumn('event.reservation_record_id', 'r.id')
                ->where('event.event_type', 'departure_follow_up')
                ->latest('event.id')->limit(1)->select('event.note'), 'departure_follow_up');
        } else {
            $query->where('r.check_in', '<=', $date)
                ->where('r.check_out', '>', $date)->where('r.lifecycle_state', 'checked_in');
        }

        $unassignedCount = (clone $query)->whereNull('r.assigned_room_id')->count();
        $queueTotal = null;
        if (isset($input['q']) && trim($input['q']) !== '') {
            $queueTotal = (clone $query)->count();
            $text = trim($input['q']);
            $pattern = fn (string $value): string => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)).'%';
            $query->where(function ($search) use ($pattern, $text): void {
                $term = $pattern($text);
                foreach (['r.reference', 'rm.number', 'g.first_name', 'g.last_name'] as $column) {
                    $search->orWhereRaw("LOWER($column) LIKE ? ESCAPE '!'", [$term]);
                }
                $parts = preg_split('/\s+/u', $text, 2);
                if (count($parts) === 2) {
                    $search->orWhere(fn ($name) => $name
                        ->whereRaw("LOWER(g.first_name) LIKE ? ESCAPE '!'", [$pattern($parts[0])])
                        ->whereRaw("LOWER(g.last_name) LIKE ? ESCAPE '!'", [$pattern($parts[1])]));
                }
                if (preg_match('/^[1-9][0-9]{0,17}$/D', $text)) {
                    $search->orWhere('r.id', $text);
                }
            });
        }
        $page = $query->orderBy('r.id')->addSelect(
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
                'departureFollowUp' => $record->departure_follow_up ?? null,
            ])->values(),
            'meta' => [
                'date' => $date, 'kind' => $kind, 'unassignedCount' => $unassignedCount,
                'queueTotal' => $queueTotal ?? $page->total(),
                'page' => $page->currentPage(), 'total' => $page->total(),
                'perPage' => $page->perPage(), 'totalPages' => $page->lastPage(),
                'determinesHousekeepingReadiness' => false,
            ],
        ]);
    }

    public function followUp(Request $request, Hotel $hotel, string $booking): JsonResponse
    {
        $input = $request->validate(['note' => ['required', 'string', 'max:500']]);
        abort_if(trim($input['note']) === '', 422, 'Enter a follow-up note.');
        DB::transaction(function () use ($request, $hotel, $booking, $input): void {
            $stay = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $booking)->lockForUpdate()->first();
            abort_unless($stay, 404);
            abort_unless($stay->lifecycle_state === 'checked_in', 409, 'Only checked-in stays can be flagged.');
            DB::table('reservation_booking_events')->insert([
                'hotel_id' => $hotel->id, 'reservation_record_id' => $stay->id,
                'actor_id' => $request->user()->id, 'event_type' => 'departure_follow_up',
                'old_state' => 'checked_in', 'new_state' => 'checked_in', 'note' => $input['note'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);

        return response()->json(['data' => ['note' => $input['note']]], 201);
    }

    public function extend(Request $request, Hotel $hotel, string $booking, ReservationExtensionService $service): JsonResponse
    {
        $input = $request->validate([
            'expectedCheckOut' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:expectedCheckOut'],
            'expectedNightlyMinor' => ['sometimes', 'required', 'integer', 'min:0', 'prohibits:expectedQuoteToken'],
            'expectedQuoteToken' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        return response()->json(['data' => $service->extend($hotel, (int) $request->user()->id, (int) $booking, $input)]);
    }

    public function previewAmendment(Request $request, Hotel $hotel, string $booking, ReservationAmendmentService $amendments): JsonResponse
    {
        $data = $request->validate([
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
            'reviewFingerprint' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        return response()->json(['data' => $amendments->amend(
            $hotel, (int) $request->user()->id, (int) $booking, $data, true
        )]);
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

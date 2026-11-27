<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomInventoryService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GroupAllotmentController
{
    public function index(Hotel $hotel): JsonResponse
    {
        $rows = DB::table('group_allotments as hold')
            ->join('room_types as type', function ($join): void {
                $join->on('type.id', '=', 'hold.room_type_id')
                    ->on('type.hotel_id', '=', 'hold.hotel_id');
            })
            ->leftJoin('users as creator', 'creator.id', '=', 'hold.created_by')
            ->leftJoin('users as releaser', 'releaser.id', '=', 'hold.released_by')
            ->where('hold.hotel_id', $hotel->id)->orderByDesc('hold.id')->limit(100)
            ->get(['hold.*', 'type.name as room_type_name',
                'creator.name as creator_name', 'releaser.name as releaser_name']);
        return response()->json(['data' => $rows->map(fn (object $row): array => $this->present($row))->values(),
            'meta' => ['limit' => 100, 'hasMore' => DB::table('group_allotments')
                ->where('hotel_id', $hotel->id)->count() > 100]]);
    }

    public function store(Request $request, Hotel $hotel, RoomInventoryService $inventory): JsonResponse
    {
        $input = $request->validate([
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:100'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'roomTypeId' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
            'holdMinutes' => ['required', 'integer', 'min:15', 'max:1440'],
        ]);
        $input['name'] = trim($input['name']);
        if (mb_strlen($input['name']) < 2) {
            throw new HttpException(422, 'A group or company name is required.');
        }
        $stay = $inventory->stay($input['checkIn'], $input['checkOut']);
        if ($stay->arrival < now($hotel->timezone ?: 'Asia/Manila')->toDateString()) {
            throw new HttpException(422, 'An allotment cannot begin in the past.');
        }
        $fingerprint = hash('sha256', json_encode([
            $input['name'], (int) $input['roomTypeId'], (int) $input['quantity'],
            $stay->arrival, $stay->departure, (int) $input['holdMinutes'],
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($hotel, $request, $input, $fingerprint, $stay, $inventory): array {
            \App\Services\PlatformHotelAccess::requireNewBooking(\App\Services\PlatformHotelAccess::lock((int) $hotel->id));
            // Same stable lock used by booking and maintenance inventory writes.
            $type = DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $input['roomTypeId'])->lockForUpdate()->first();
            abort_unless($type !== null, 404);
            $previous = DB::table('group_allotments')->where('hotel_id', $hotel->id)
                ->where('idempotency_key', $input['idempotencyKey'])->first();
            if ($previous !== null) {
                if (! hash_equals($previous->payload_sha256, $fingerprint)) {
                    throw new HttpException(409, 'Allotment request key was already used for different details.');
                }
                return ['id' => (string) $previous->id, 'replayed' => true];
            }

            foreach ($inventory->availability($hotel, (int) $type->id, $stay)['nights'] as $night) {
                if ($night['remaining'] < (int) $input['quantity']) {
                    throw new HttpException(409, 'Not enough unsold rooms remain on every requested night.');
                }
            }
            $id = DB::table('group_allotments')->insertGetId([
                'hotel_id' => $hotel->id, 'reference' => 'HOLD-'.Str::upper(Str::random(12)),
                'name' => $input['name'], 'room_type_id' => $type->id,
                'quantity' => $input['quantity'], 'check_in' => $stay->arrival,
                'check_out' => $stay->departure, 'status' => 'held',
                'expires_at' => now()->addMinutes((int) $input['holdMinutes']),
                'idempotency_key' => $input['idempotencyKey'],
                'payload_sha256' => $fingerprint, 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => (string) $id, 'replayed' => false];
        }, 3);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function release(Request $request, Hotel $hotel, string $allotment): JsonResponse
    {
        $result = DB::transaction(function () use ($hotel, $request, $allotment): array {
            $row = DB::table('group_allotments')->where('hotel_id', $hotel->id)
                ->where('id', $allotment)->first();
            abort_unless($row !== null, 404);
            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $row->room_type_id)->lockForUpdate()->first();
            $row = DB::table('group_allotments')->where('hotel_id', $hotel->id)
                ->where('id', $allotment)->lockForUpdate()->first();
            if ($row->status !== 'held') {
                throw new HttpException(409, 'This allotment is already released or converted.');
            }
            DB::table('group_allotments')->where('id', $row->id)->update([
                'status' => 'released', 'released_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            return ['id' => (string) $row->id, 'status' => 'released'];
        }, 3);
        return response()->json(['data' => $result]);
    }

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id, 'reference' => $row->reference,
            'name' => $row->name, 'roomTypeId' => (string) $row->room_type_id,
            'roomTypeName' => $row->room_type_name, 'quantity' => (int) $row->quantity,
            'checkIn' => $row->check_in, 'checkOut' => $row->check_out,
            'expiresAt' => \Carbon\CarbonImmutable::parse($row->expires_at, config('app.timezone', 'UTC'))->toIso8601String(),
            'createdByName' => $row->creator_name,
            'releasedByName' => $row->releaser_name,
            'status' => $row->status === 'held'
                && (string) $row->expires_at <= now()->toDateTimeString()
                ? 'expired' : $row->status,
            'convertedGroupId' => $row->converted_group_id === null ? null : (string) $row->converted_group_id,
        ];
    }
}

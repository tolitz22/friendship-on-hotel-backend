<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomRateQuoteService;
use App\Domain\Reservations\StayPeriod;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReservationFinanceController
{
    public function rates(Hotel $hotel): JsonResponse
    {
        $rates = DB::table('room_rate_plans')->where('hotel_id', $hotel->id)
            ->orderBy('room_type_id')->orderBy('name')->get();
        return response()->json(['data' => $rates->map(fn (object $rate): array => [
            'id' => (string) $rate->id,
            'roomTypeId' => (string) $rate->room_type_id,
            'name' => $rate->name,
            'currency' => $rate->currency,
            'nightlyMinor' => (int) $rate->nightly_minor,
            'active' => (bool) $rate->is_active,
        ])->values()]);
    }

    public function createRate(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'roomTypeId' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:100'],
            'nightlyMinor' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ]);
        if (trim($input['name']) === '') {
            throw ValidationException::withMessages(['name' => ['A rate name is required.']]);
        }

        $result = DB::transaction(function () use ($hotel, $request, $input): array {
            $type = DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $input['roomTypeId'])->lockForUpdate()->first();
            abort_unless($type !== null, 404);
            $name = trim($input['name']);
            $existing = DB::table('room_rate_plans')->where('hotel_id', $hotel->id)
                ->where('room_type_id', $type->id)->where('name', $name)->exists();
            if ($existing) {
                throw ValidationException::withMessages(['name' => ['Rate plan name already exists for this room type.']]);
            }
            $currency = strtoupper((string) ($hotel->currency ?: 'PHP'));
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw ValidationException::withMessages(['currency' => ['Hotel currency must be a valid ISO-style three-letter code.']]);
            }
            $id = DB::table('room_rate_plans')->insertGetId([
                'hotel_id' => $hotel->id,
                'room_type_id' => $type->id,
                'name' => $name,
                'nightly_minor' => $input['nightlyMinor'],
                'currency' => $currency,
                'is_active' => true,
                'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => (string) $id, 'roomTypeId' => (string) $type->id,
                'currency' => $currency, 'nightlyMinor' => (int) $input['nightlyMinor']];
        }, 3);

        return response()->json(['data' => $result], 201);
    }

    public function quote(Request $request, Hotel $hotel, RoomRateQuoteService $quotes): JsonResponse
    {
        $input = $request->validate([
            'roomTypeId' => ['required', 'integer', 'min:1'],
            'ratePlanId' => ['required', 'integer', 'min:1'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
        ]);
        return response()->json(['data' => $quotes->quote(
            $hotel, (int) $input['roomTypeId'], (int) $input['ratePlanId'],
            new StayPeriod($input['checkIn'], $input['checkOut'])
        )]);
    }

    public function folio(Hotel $hotel, string $booking): JsonResponse
    {
        $record = DB::table('reservation_records')
            ->where('hotel_id', $hotel->id)->where('id', $booking)->first();
        abort_unless($record !== null, 404);

        $folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
            ->where('reservation_record_id', $record->id)->first();
        if ($folio === null) {
            return response()->json([
                'data' => null,
                'meta' => ['priced' => false, 'message' => 'No financial snapshot exists for this reservation.'],
            ]);
        }

        $entries = DB::table('folio_entries')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folio->id)->orderBy('stay_date')->get();
        $subtotal = $entries->sum('amount_minor');

        return response()->json([
            'data' => [
                'id' => (string) $folio->id,
                'reservationId' => (string) $record->id,
                'currency' => $folio->currency,
                'roomSubtotalMinor' => (int) $subtotal,
                'entries' => $entries->map(fn (object $entry): array => [
                    'id' => (string) $entry->id,
                    'type' => $entry->entry_type,
                    'description' => $entry->description,
                    'stayDate' => $entry->stay_date,
                    'amountMinor' => (int) $entry->amount_minor,
                    'ratePlanId' => $entry->rate_plan_id === null ? null : (string) $entry->rate_plan_id,
                ])->values(),
                'taxesCalculated' => false,
                'feesCalculated' => false,
                'paymentsRecorded' => false,
                'isFinalPayableTotal' => false,
            ],
        ]);
    }
}

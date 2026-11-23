<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReservationExtensionService
{
    public function extend(Hotel $hotel, int $actor, int $bookingId, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $bookingId, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $booking = $hotel->reservationRecords()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
            $departure = $booking->check_out->toDateString();
            if ($booking->lifecycle_state !== 'checked_in' || $booking->assigned_room_id === null) {
                throw new HttpException(409, 'A checked-in booking with an assigned room is required.');
            }
            if ($input['expectedCheckOut'] !== $departure) {
                throw new HttpException(409, 'The departure date changed. Reload the booking and check the current dates.');
            }
            if ($input['checkOut'] <= max($departure, $today)) {
                throw new HttpException(422, 'Choose a new departure after today and the current departure date.');
            }

            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $booking->room_type_id)->lockForUpdate()->firstOrFail();
            $folioService = app(FolioAccountingService::class);
            $folio = $folioService->findFolio($hotel, $bookingId, true);
            $folioService->assertNotSigned($hotel, $folio);
            $firstOpenDate = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)
                ->lockForUpdate()->first()?->business_date;
            $closedNight = DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                ->where('status', 'completed')->where('business_date', '>=', $departure)
                ->where('business_date', '<', $input['checkOut'])->exists();
            if ($closedNight || ($firstOpenDate !== null && $departure < $firstOpenDate)) {
                throw new HttpException(409, 'The extension includes a closed business date. Ask finance to review the missed nights.');
            }

            $inventory = app(RoomInventoryService::class);
            $stay = $inventory->stay($departure, $input['checkOut']);
            if (! $inventory->availability($hotel, (int) $booking->room_type_id, $stay)['canBook']) {
                throw new HttpException(409, 'The room type has no inventory for one or more added nights.');
            }
            $roomConflict = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('assigned_room_id', $booking->assigned_room_id)->where('id', '!=', $bookingId)
                ->where(function ($query) use ($stay): void {
                    $query->where('lifecycle_state', 'checked_in')
                        ->orWhere(function ($overlap) use ($stay): void {
                            $overlap->where('check_in', '<', $stay->departure)->where('check_out', '>', $stay->arrival)
                                ->where(fn ($status) => $status->whereNull('lifecycle_state')
                                    ->orWhereNotIn('lifecycle_state', config('booking.released_states', ['cancelled', 'checked_out', 'no_show'])));
                        });
                })->exists();
            $blocked = DB::table('room_inventory_blocks')->where('hotel_id', $hotel->id)
                ->where('room_id', $booking->assigned_room_id)->where('is_active', true)
                ->where('check_in', '<', $stay->departure)->where('check_out', '>', $stay->arrival)->exists();
            if ($roomConflict || $blocked) {
                throw new HttpException(409, 'The current room has a booking conflict or maintenance block on an added night.');
            }

            $rateId = DB::table('folio_entries')->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->where('entry_type', 'room_night')->where('stay_date', '<', $departure)
                ->orderByDesc('stay_date')->value('rate_plan_id');
            if ($rateId === null) {
                throw new HttpException(409, 'The folio has no existing room rate plan.');
            }
            $rate = app(RoomRateQuoteService::class)->quote($hotel, (int) $booking->room_type_id, (int) $rateId, $stay);
            if ($rate['currency'] !== $folio->currency) {
                throw new HttpException(409, 'The room rate currency differs from the guest folio currency.');
            }

            $result = [
                'previousCheckOut' => $departure, 'checkOut' => $stay->departure,
                'nights' => count($rate['nights']), 'nightlyMinor' => $rate['nightlyMinor'],
                'subtotalMinor' => $rate['roomSubtotalMinor'], 'currency' => $rate['currency'],
                'saved' => isset($input['expectedNightlyMinor']),
            ];
            if (! $result['saved']) {
                return $result;
            }
            if ((int) $input['expectedNightlyMinor'] !== $rate['nightlyMinor']) {
                throw new HttpException(409, 'The room rate changed. Check availability and review the new amount.');
            }

            $timestamps = ['created_at' => now(), 'updated_at' => now()];
            foreach ($rate['nights'] as $night) {
                DB::table('folio_entries')->insert([
                    ...$timestamps, 'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                    'entry_type' => 'room_night', 'description' => 'Stay extension · '.$night,
                    'stay_date' => $night, 'amount_minor' => $rate['nightlyMinor'],
                    'rate_plan_id' => $rateId, 'actor_id' => $actor,
                ]);
            }
            $booking->check_out = $stay->departure;
            $booking->save();
            DB::table('reservation_date_amendments')->insert([
                ...$timestamps, 'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
                'actor_id' => $actor, 'old_check_in' => $booking->check_in->toDateString(),
                'new_check_in' => $booking->check_in->toDateString(),
                'old_check_out' => $departure, 'new_check_out' => $stay->departure,
            ]);
            DB::table('reservation_booking_events')->insert([
                ...$timestamps, 'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
                'actor_id' => $actor, 'event_type' => 'stay_extended', 'old_state' => 'checked_in',
                'new_state' => 'checked_in', 'note' => 'Departure changed from '.$departure.' to '.$stay->departure.'.',
            ]);

            return $result;
        }, 3);
    }
}

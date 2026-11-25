<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Amend dates without changing the room type, guest, status or quoted price.
 * Every write to the same room type uses the shared inventory row lock.
 */
final class ReservationAmendmentService
{
    public function amend(Hotel $hotel, int $actor, int $bookingId, array $input, bool $preview = false): array
    {
        $newStay = new StayPeriod($input['checkIn'], $input['checkOut']);
        if ($newStay->nights() > (int) config('booking.maximum_nights', 31)) {
            throw new HttpException(422, 'Requested stay exceeds the maximum nights.');
        }

        return DB::transaction(function () use ($hotel, $actor, $bookingId, $newStay, $preview): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $snapshot = DB::table('reservation_records')->where('hotel_id', $hotel->id)->where('id', $bookingId)->first();
            abort_unless($snapshot !== null, 404);

            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $snapshot->room_type_id)->lockForUpdate()->firstOrFail();
            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking !== null, 404);

            if ($booking->lifecycle_state !== 'confirmed') {
                throw new HttpException(409, 'Only confirmed reservations may change stay dates.');
            }

            // Prevent changing priced nights without an approved repricing
            // and charge-reversal workflow. Original folio stays immutable.
            if (! $preview && DB::table('reservation_folios')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->exists()) {
                throw new HttpException(409, 'Priced reservations require the future repricing workflow to amend dates.');
            }

            $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
            if ($newStay->arrival < $today || ($today >= $booking->check_in && $today < $booking->check_out)) {
                throw new HttpException(409, 'Choose dates on or after today. A confirmed stay currently in its scheduled arrival window cannot be amended here.');
            }

            if (! $preview && $booking->check_in === $newStay->arrival && $booking->check_out === $newStay->departure) {
                return ['id' => (string) $bookingId, 'checkIn' => $booking->check_in,
                    'checkOut' => $booking->check_out, 'unchanged' => true];
            }

            // The availability projection includes this reservation. Remove its
            // one nightly unit only on nights where the original stay overlaps.
            $inventory = app(RoomInventoryService::class)->availability(
                $hotel, (int) $booking->room_type_id, $newStay
            );
            $oldStay = new StayPeriod($booking->check_in, $booking->check_out);
            foreach ($inventory['nights'] as $night) {
                $ownUnit = $oldStay->containsNight($night['date']) ? 1 : 0;
                if ($night['reserved'] + $night['blocked'] - $ownUnit >= $night['physicalRooms']) {
                    throw new HttpException(409, 'No room-type inventory for every amended night.');
                }
            }

            // An already assigned physical room cannot overlap another assigned
            // reservation or a room block in any of the newly requested nights.
            if ($booking->assigned_room_id !== null) {
                $blocked = DB::table('room_inventory_blocks')
                    ->where('hotel_id', $hotel->id)
                    ->where('room_id', $booking->assigned_room_id)
                    ->where('is_active', true)
                    ->where('check_in', '<', $newStay->departure)
                    ->where('check_out', '>', $newStay->arrival)->exists();
                $collision = DB::table('reservation_records')
                    ->where('hotel_id', $hotel->id)
                    ->where('assigned_room_id', $booking->assigned_room_id)
                    ->where('id', '!=', $bookingId)
                    ->where('check_in', '<', $newStay->departure)
                    ->where('check_out', '>', $newStay->arrival)
                    ->where(function ($query): void {
                        $query->whereNull('lifecycle_state')->orWhereNotIn(
                            'lifecycle_state', config('booking.released_states', ['cancelled', 'checked_out', 'no_show'])
                        );
                    })->exists();
                if ($blocked || $collision) {
                    throw new HttpException(409, 'Assigned physical room conflicts with amended nights.');
                }
            }

            if ($preview) {
                $folioService = app(FolioAccountingService::class);
                $folio = $folioService->findFolio($hotel, $bookingId, true);
                $folioService->assertNotSigned($hotel, $folio);
                $entries = DB::table('folio_entries')->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                    ->where('entry_type', 'room_night')->orderBy('stay_date')->orderBy('id')->get();
                $planIds = $entries->pluck('rate_plan_id')->unique()->values();
                abort_if($entries->isEmpty() || $planIds->count() !== 1 || $planIds->first() === null, 409, 'A single original rate plan is required for automatic repricing.');
                $snapshot = DB::table('booking_pricing_snapshots')->where('hotel_id', $hotel->id)->where('reservation_record_id', $bookingId)->first();
                $original = $snapshot === null ? [] : json_decode($snapshot->snapshot, true, 512, JSON_THROW_ON_ERROR);
                $rate = $snapshot !== null
                    ? app(BookingDynamicQuoteService::class)->quote($hotel, (int) $booking->room_type_id, (int) $planIds->first(), $newStay, $original['corporateCode'] ?? null, $original['promoCode'] ?? null)
                    : app(RoomRateQuoteService::class)->quote($hotel, (int) $booking->room_type_id, (int) $planIds->first(), $newStay);
                abort_if($rate['currency'] !== $folio->currency, 409, 'The new rate currency differs from the guest folio.');
                $oldSubtotal = (int) $entries->sum('amount_minor');
                $summary = $folioService->summaryFor($folio);
                $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->first();
                $earliest = min($booking->check_in, $newStay->arrival);
                $latest = max($booking->check_out, $newStay->departure);
                $closed = ($clock !== null && $earliest < $clock->business_date) || DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                    ->where('status', 'completed')->where('business_date', '>=', $earliest)->where('business_date', '<', $latest)->exists();
                $review = [
                    'id' => (string) $bookingId, 'oldCheckIn' => $booking->check_in, 'oldCheckOut' => $booking->check_out,
                    'checkIn' => $newStay->arrival, 'checkOut' => $newStay->departure, 'currency' => $folio->currency,
                    'originalRoomSubtotalMinor' => $oldSubtotal, 'proposedRoomSubtotalMinor' => $rate['roomSubtotalMinor'],
                    'differenceMinor' => $rate['roomSubtotalMinor'] - $oldSubtotal,
                    'nightlyRates' => $rate['nightlyRates'] ?? array_map(fn ($date) => ['date' => $date, 'amountMinor' => $rate['nightlyMinor'], 'source' => 'base', 'ruleId' => null], $rate['nights']),
                    'policyTerms' => $rate['policyTerms'] ?? null, 'currentFolioSummary' => $summary,
                    'closedPeriodAffected' => $closed, 'previewOnly' => true, 'saved' => false,
                ];

                // Bind the review to the property, actor, dates, pricing and current folio.
                // The future commit path must recompute this under the same locks.
                $review['reviewFingerprint'] = hash_hmac('sha256', json_encode([
                    'hotelId' => (string) $hotel->id, 'actorId' => (string) $actor,
                    'bookingVersion' => (string) $booking->updated_at,
                    'folioId' => (string) $folio->id, 'review' => $review,
                ], JSON_THROW_ON_ERROR), (string) config('app.key'));

                return $review;
            }

            DB::table('reservation_records')->where('hotel_id', $hotel->id)->where('id', $bookingId)->update([
                'check_in' => $newStay->arrival, 'check_out' => $newStay->departure, 'updated_at' => now(),
            ]);
            DB::table('reservation_date_amendments')->insert([
                'hotel_id' => $hotel->id,
                'reservation_record_id' => $bookingId,
                'actor_id' => $actor,
                'old_check_in' => $booking->check_in,
                'old_check_out' => $booking->check_out,
                'new_check_in' => $newStay->arrival,
                'new_check_out' => $newStay->departure,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('reservation_booking_events')->insert([
                'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId, 'actor_id' => $actor,
                'event_type' => 'stay_amended', 'old_state' => 'confirmed',
                'new_state' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['id' => (string) $bookingId, 'checkIn' => $newStay->arrival,
                'checkOut' => $newStay->departure, 'unchanged' => false];
        }, 3);
    }
}

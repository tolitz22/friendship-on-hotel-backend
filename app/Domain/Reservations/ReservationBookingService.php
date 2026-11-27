<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Conservative room-type inventory writer. All application booking mutations
 * must take the same stable room-type row lock on MySQL/InnoDB and PostgreSQL.
 */
final class ReservationBookingService
{
    public function create(Hotel $hotel, int $actorId, array $data): array
    {
        $period = new StayPeriod($data['checkIn'], $data['checkOut']);
        if ($period->nights() < (int) config('booking.minimum_nights', 1)
            || $period->nights() > (int) config('booking.maximum_nights', 31)) {
            throw new HttpException(422, 'Stay must be between 1 and 31 nights.');
        }

        // Keep the original hash byte-for-byte for legacy unpriced retries.
        // Include the selected plan only when this is a priced booking.
        $fingerprintFields = [
            'guestId' => (int) $data['guestId'],
            'roomTypeId' => (int) $data['roomTypeId'],
            'checkIn' => $period->arrival,
            'checkOut' => $period->departure,
        ];
        if (isset($data['ratePlanId'])) {
            $fingerprintFields['ratePlanId'] = (int) $data['ratePlanId'];
        }
        if (isset($data['dynamicQuoteToken'])) {
            $fingerprintFields['dynamicQuoteToken'] = $data['dynamicQuoteToken'];
            $fingerprintFields['corporateCode'] = $data['corporateCode'] ?? null;
            $fingerprintFields['promoCode'] = $data['promoCode'] ?? null;
        }
        $fingerprint = hash('sha256', json_encode($fingerprintFields, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($hotel, $actorId, $data, $period, $fingerprint): array {
            \App\Services\PlatformHotelAccess::requireNewBooking(\App\Services\PlatformHotelAccess::lock((int) $hotel->id));
            // Lock a stable parent row, not a possibly empty set of reservation rows.
            $type = DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $data['roomTypeId'])->lockForUpdate()->first();
            abort_unless($type !== null, 404);

            $previous = DB::table('reservation_booking_requests')
                ->where('hotel_id', $hotel->id)
                ->where('idempotency_key', $data['idempotencyKey'])->first();
            if ($previous !== null) {
                if (! hash_equals($previous->payload_sha256, $fingerprint)) {
                    throw new HttpException(409, 'Idempotency key already used for a different booking.');
                }

                return $this->present($hotel->id, (int) $previous->reservation_record_id, true);
            }

            abort_unless(DB::table('guests')->where('hotel_id', $hotel->id)
                ->where('id', $data['guestId'])->exists(), 404);

            // The same room-type row is locked above. Availability and room
            // blocks are evaluated under this lock before making the sale.
            $availability = app(RoomInventoryService::class)->availability(
                $hotel, (int) $type->id, $period
            );
            if (! $availability['canBook']) {
                throw new HttpException(409, 'No inventory remains for every requested night.');
            }

            // Optional price snapshot: existing legacy/unpriced bookings stay
            // supported and are never assigned an invented nightly rate.
            $quote = isset($data['ratePlanId'])
                ? app(RoomRateQuoteService::class)->quote(
                    $hotel, (int) $type->id, (int) $data['ratePlanId'], $period
                )
                : null;

            $dynamic = null;
            if (isset($data['dynamicQuoteToken'])) {
                abort_unless(config('booking.dynamic_pricing_enabled', false), 422, 'Dynamic booking pricing is not enabled.');
                abort_unless(isset($data['ratePlanId']), 422, 'Select a rate plan.');
                $dynamic = app(BookingDynamicQuoteService::class)->quote($hotel, (int) $type->id, (int) $data['ratePlanId'], $period,
                    $data['corporateCode'] ?? null, $data['promoCode'] ?? null);
                abort_unless(hash_equals($dynamic['quoteToken'], $data['dynamicQuoteToken']), 422, 'The reviewed price or policy changed. Request a new quote.');
                $quote = $dynamic;
            }

            $id = DB::table('reservation_records')->insertGetId([
                'hotel_id' => $hotel->id,
                'room_type_id' => $type->id,
                'guest_id' => $data['guestId'],
                'assigned_room_id' => null,
                'reference' => 'BK-'.Str::upper(Str::random(16)),
                'check_in' => $period->arrival,
                'check_out' => $period->departure,
                'lifecycle_state' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('reservation_booking_requests')->insert([
                'hotel_id' => $hotel->id,
                'idempotency_key' => $data['idempotencyKey'],
                'payload_sha256' => $fingerprint,
                'reservation_record_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($quote !== null) {
                $folioId = DB::table('reservation_folios')->insertGetId([
                    'hotel_id' => $hotel->id,
                    'reservation_record_id' => $id,
                    'currency' => $quote['currency'],
                    'created_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($quote['nights'] as $index => $night) {
                    DB::table('folio_entries')->insert([
                        'folio_id' => $folioId, 'hotel_id' => $hotel->id,
                        'entry_type' => 'room_night',
                        'description' => 'Room night '.$night,
                        'amount_minor' => $dynamic === null ? $quote['nightlyMinor'] : $dynamic['nightlyRates'][$index]['amountMinor'],
                        'rate_plan_id' => $data['ratePlanId'],
                        'stay_date' => $night,
                        'actor_id' => $actorId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            if ($dynamic !== null) {
                DB::table('booking_pricing_snapshots')->insert([
                    'hotel_id' => $hotel->id, 'reservation_record_id' => $id,
                    'quote_token' => $dynamic['quoteToken'],
                    'snapshot' => json_encode($dynamic, JSON_THROW_ON_ERROR),
                    'created_by' => $actorId, 'created_at' => now(),
                ]);
            }

            $this->audit($hotel->id, $id, $actorId, 'created', null, 'confirmed');

            return $this->present($hotel->id, $id, false);
        }, 3);
    }

    public function cancel(Hotel $hotel, int $actorId, int $reservationId, bool $noShow = false): array
    {
        return DB::transaction(function () use ($hotel, $actorId, $reservationId, $noShow): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $record = DB::table('reservation_records')
                ->where('hotel_id', $hotel->id)->where('id', $reservationId)->first();
            abort_unless($record !== null, 404);

            if ($noShow) {
                // Serialize the no-show status change with nightly posting.
                DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            }

            // Lock in the same order as create (room type, then reservation).
            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $record->room_type_id)->lockForUpdate()->first();
            $record = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $reservationId)->lockForUpdate()->first();
            abort_unless($record !== null, 404);

            if ($record->lifecycle_state !== 'confirmed') {
                throw new HttpException(409, $noShow
                    ? 'Only a confirmed booking can be marked as a no-show.'
                    : 'Only confirmed reservations can be cancelled through this endpoint.');
            }

            $folioId = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $record->id)->value('id');
            $priced = $folioId !== null;
            if ($priced && !$noShow) {
                throw new HttpException(409, 'Priced reservations require a financial reversal policy before cancellation.');
            }

            if ($noShow) {
                $cutoff = NoShowWorkflow::eligibleAt($hotel, $record->check_in);
                if (now()->lessThan($cutoff)) {
                    throw new HttpException(409, 'This booking can be marked as a no-show from '.$cutoff->format('M j, Y H:i T').'.');
                }
                if ($priced && ($blocker = NoShowWorkflow::accountingBlocker((int) $hotel->id, (int) $folioId, markingNoShow: true)) !== null) {
                    throw new HttpException(409, $blocker.' Resolve it with Finance before releasing the booking.');
                }
            }
            $state = $noShow ? 'no_show' : 'cancelled';
            // This starter workflow does not compute refunds or cancellation fees.
            DB::table('reservation_records')->where('id', $record->id)->update([
                'lifecycle_state' => $state, 'updated_at' => now(),
            ]);
            $this->audit($hotel->id, (int) $record->id, $actorId, $state, 'confirmed', $state,
                $noShow && $priced ? 'Financial review required: original charges and payments retained. No fee, waiver or refund applied.' : null);

            return [
                ...$this->present($hotel->id, (int) $record->id, false),
                ...($noShow ? ['financialReviewRequired' => $priced] : []),
            ];
        }, 3);
    }

    private function audit(int $hotelId, int $id, int $actorId, string $event, ?string $from, string $to, ?string $note = null): void
    {
        DB::table('reservation_booking_events')->insert([
            'hotel_id' => $hotelId,
            'reservation_record_id' => $id,
            'actor_id' => $actorId,
            'event_type' => $event,
            'old_state' => $from,
            'new_state' => $to,
            ...($note === null ? [] : ['note' => $note]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function present(int $hotelId, int $id, bool $replayed): array
    {
        $row = DB::table('reservation_records')
            ->where('hotel_id', $hotelId)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return [
            'id' => (string) $row->id,
            'reference' => $row->reference,
            'hotelId' => (string) $row->hotel_id,
            'guestId' => (string) $row->guest_id,
            'roomTypeId' => (string) $row->room_type_id,
            'assignedRoomId' => $row->assigned_room_id === null ? null : (string) $row->assigned_room_id,
            'checkIn' => $row->check_in,
            'checkOut' => $row->check_out,
            'status' => $row->lifecycle_state,
            'idempotentReplay' => $replayed,
        ];
    }
}

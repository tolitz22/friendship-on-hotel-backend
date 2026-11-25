<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Internal append-only revision writer. Not a booking save endpoint.
 * Call only with a server-recomputed, verified review while holding the
 * amendment transaction and inventory locks. Accounting readers must be
 * integrated before enabling this writer in the live amendment workflow.
 */
final class ReservationPricingRevisionService
{
    public function record(Hotel $hotel, int $actor, int $bookingId, array $review): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Pricing revision requires an enclosing amendment transaction.');
        }

        app(ReadinessWriteGuard::class)->lockHotel($hotel);
        $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('id', $bookingId)->lockForUpdate()->first();
        abort_unless($booking !== null, 404);

        $accounting = app(FolioAccountingService::class);
        $folio = $accounting->findFolio($hotel, $bookingId, true);
        $accounting->assertNotSigned($hotel, $folio);
        $fingerprint = $review['reviewFingerprint'] ?? '';
        $signed = $review;
        unset($signed['reviewFingerprint']);
        $expected = hash_hmac('sha256', json_encode([
            'hotelId' => (string) $hotel->id, 'actorId' => (string) $actor,
            'bookingVersion' => (string) $booking->updated_at,
            'folioId' => (string) $folio->id, 'review' => $signed,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
        if (!is_string($fingerprint) || !hash_equals($expected, $fingerprint)
            || ($review['id'] ?? null) !== (string) $bookingId
            || ($review['oldCheckIn'] ?? null) !== $booking->check_in
            || ($review['oldCheckOut'] ?? null) !== $booking->check_out
            || ($review['currency'] ?? null) !== $folio->currency
            || $booking->lifecycle_state !== 'confirmed'
            || ($review['previewOnly'] ?? null) !== true
            || ($review['saved'] ?? null) !== false
            || ($review['currentFolioSummary'] ?? null) !== $accounting->summaryFor($folio)) {
            throw new HttpException(409, 'The booking or financial review has changed.');
        }

        $oldStay = new StayPeriod($booking->check_in, $booking->check_out);
        $newStay = new StayPeriod($review['checkIn'], $review['checkOut']);
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
        $earliest = min($oldStay->arrival, $newStay->arrival);
        $latest = max($oldStay->departure, $newStay->departure);
        if (($review['requiresFinancialCorrection'] ?? true)
            || ($clock !== null && $earliest < $clock->business_date)
            || DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                ->where('status', 'completed')->where('business_date', '>=', $earliest)
                ->where('business_date', '<', $latest)->exists()
            || DB::table('financial_folio_night_assessments')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->exists()) {
            throw new HttpException(409, 'An independent financial correction is required.');
        }

        if (DB::table('reservation_pricing_revisions')->where('hotel_id', $hotel->id)
            ->where('reservation_record_id', $bookingId)->exists()) {
            throw new HttpException(409, 'A pricing revision already exists for this booking.');
        }

        $entries = DB::table('folio_entries')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folio->id)->where('entry_type', 'room_night')
            ->orderBy('stay_date')->lockForUpdate()->get();
        $plans = $entries->pluck('rate_plan_id')->unique()->values();
        if ($entries->count() !== $oldStay->nights() || $plans->count() !== 1 || $plans->first() === null) {
            throw new HttpException(409, 'Original nightly charge records require manual review.');
        }

        $original = 0;
        $dates = [];
        foreach ($entries as $entry) {
            if (!$oldStay->containsNight($entry->stay_date) || isset($dates[$entry->stay_date])) {
                throw new HttpException(409, 'Original nightly charge dates do not match the booking.');
            }
            $dates[$entry->stay_date] = true;
            $original = $this->add($original, (int) $entry->amount_minor);
        }

        $replacement = 0;
        $dates = [];
        $nights = $review['nightlyRates'] ?? [];
        foreach ($nights as $night) {
            if (!$newStay->containsNight($night['date']) || isset($dates[$night['date']])
                || !is_int($night['amountMinor']) || $night['amountMinor'] < 0
                || !is_string($night['source']) || strlen($night['source']) > 100) {
                throw new HttpException(409, 'Replacement nightly charges are invalid.');
            }
            $dates[$night['date']] = true;
            $replacement = $this->add($replacement, $night['amountMinor']);
        }
        if (count($dates) !== $newStay->nights()
            || $original !== $review['originalRoomSubtotalMinor']
            || $replacement !== $review['proposedRoomSubtotalMinor']
            || $replacement - $original !== $review['differenceMinor']) {
            throw new HttpException(409, 'Nightly charge totals do not match the reviewed amounts.');
        }

        $revision = DB::table('reservation_pricing_revisions')->insertGetId([
            'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
            'folio_id' => $folio->id, 'actor_id' => $actor,
            'review_fingerprint' => $fingerprint, 'currency' => $folio->currency,
            'old_check_in' => $oldStay->arrival, 'old_check_out' => $oldStay->departure,
            'new_check_in' => $newStay->arrival, 'new_check_out' => $newStay->departure,
            'original_subtotal_minor' => $original, 'replacement_subtotal_minor' => $replacement,
            'created_at' => now(),
        ]);
        foreach ($entries as $entry) {
            DB::table('reservation_pricing_revision_nights')->insert([
                'revision_id' => $revision, 'original_entry_id' => $entry->id,
                'rate_plan_id' => $entry->rate_plan_id, 'stay_date' => $entry->stay_date,
                'kind' => 'reversal', 'amount_minor' => $entry->amount_minor,
                'created_at' => now(),
            ]);
        }
        foreach ($nights as $night) {
            DB::table('reservation_pricing_revision_nights')->insert([
                'revision_id' => $revision, 'rate_plan_id' => $plans->first(),
                'stay_date' => $night['date'], 'kind' => 'replacement',
                'amount_minor' => $night['amountMinor'], 'rate_source' => $night['source'],
                'created_at' => now(),
            ]);
        }

        return (int) $revision;
    }

    private function add(int $total, int $amount): int
    {
        if ($amount < 0 || $total > PHP_INT_MAX - $amount) {
            throw new HttpException(422, 'Nightly charge totals exceed accounting limits.');
        }
        return $total + $amount;
    }
}

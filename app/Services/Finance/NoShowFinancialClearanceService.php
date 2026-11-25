<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\FolioAccountingService;
use App\Domain\Reservations\NoShowWorkflow;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class NoShowFinancialClearanceService
{
    /** Internal only: caller must authorize an independent finance administrator. */
    public function approve(Hotel $hotel, int $bookingId, int $reviewId, int $actorId, string $taxClassification, string $taxBasis): array
    {
        abort_unless(NoShowWorkflow::enabled((int) $hotel->id), 409, 'No-show financial approval is not enabled.');
        if (mb_strlen(trim($taxBasis)) < 12 || mb_strlen($taxBasis) > 1000 || $actorId < 1) {
            throw ValidationException::withMessages(['taxBasis' => 'Document the independently reviewed tax treatment.']);
        }

        return DB::transaction(function () use ($hotel, $bookingId, $reviewId, $actorId, $taxClassification, $taxBasis): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking !== null && $booking->lifecycle_state === 'no_show', 409, 'Booking must remain a no-show.');
            $accounting = app(FolioAccountingService::class);
            $folio = $accounting->findFolio($hotel, $bookingId, true);
            $accounting->assertNotSigned($hotel, $folio);
            $review = DB::table('no_show_financial_reviews')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->where('folio_id', $folio->id)
                ->where('id', $reviewId)->lockForUpdate()->first();
            abort_unless($review !== null, 404);
            $gross = (int) $review->fee_minor;
            abort_unless($clock !== null && $clock->status === 'open' && $folio->currency === 'PHP'
                && (int) $review->actor_id !== $actorId, 409,
                'Independent reviewer, open PHP business date and valid tax allocation required.');
            $previous = DB::table('no_show_financial_clearances')->where('hotel_id', $hotel->id)
                ->where('review_id', $reviewId)->latest('id')->first();
            if ($previous !== null) {
                abort_unless($previous->journal_id !== null && DB::table('financial_posting_journals')
                    ->where('hotel_id', $hotel->id)->where('reverses_journal_id', $previous->journal_id)->exists(),
                    409, 'The previous clearance must have a reversing journal before it can be replaced.');
            }
            abort_if(DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                ->where('business_date', $clock->business_date)->where('status', 'completed')->exists(), 409, 'Business date already closed.');

            $expected = [];
            if ((int) $review->credit_minor > 0) $expected[(int) $review->credit_adjustment_id] = ['credit', (int) $review->credit_minor];
            if ($gross > 0) $expected[(int) $review->fee_adjustment_id] = ['debit', $gross];
            $adjustments = DB::table('folio_adjustments')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->lockForUpdate()->get();
            abort_unless($adjustments->count() === count($expected), 409, 'Folio adjustments changed; manual correction required.');
            foreach ($adjustments as $adjustment) {
                abort_unless(($expected[(int) $adjustment->id] ?? null) === [$adjustment->kind, (int) $adjustment->amount_minor],
                    409, 'Reviewed adjustment no longer matches the folio.');
            }
            abort_unless($accounting->summaryFor($folio)['netRecordedChargesMinor'] === $gross, 409, 'Folio total changed.');
            abort_if(DB::table('financial_folio_night_assessments')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->exists(), 409, 'Existing room accounting requires a separate correction.');

            $tax = app(PhilippineTaxCalculator::class)->calculate(
                $gross, $taxClassification, 'inclusive', $taxClassification === 'vat_standard' ? 1200 : 0
            );
            $vatMinor = $tax['tax_minor'];
            $journalId = app(NoShowFeeJournalService::class)->post((int) $hotel->id, $reviewId, $actorId, $vatMinor, $previous?->id);
            $id = DB::table('no_show_financial_clearances')->insertGetId([
                'hotel_id' => $hotel->id, 'review_id' => $reviewId, 'reviewed_by' => $actorId,
                'gross_minor' => $gross, 'vat_minor' => $vatMinor, 'revenue_minor' => $gross - $vatMinor,
                'journal_id' => $journalId, 'business_date' => $clock->business_date,
                'tax_basis' => $taxClassification.': '.trim($taxBasis), 'created_at' => now(),
            ]);
            return ['clearanceId' => (string) $id, 'journalId' => $journalId === null ? null : (string) $journalId,
                'grossMinor' => $gross, 'vatMinor' => $vatMinor, 'revenueMinor' => $gross - $vatMinor,
                'cleared' => true, 'refundIssued' => false];
        }, 3);
    }
}

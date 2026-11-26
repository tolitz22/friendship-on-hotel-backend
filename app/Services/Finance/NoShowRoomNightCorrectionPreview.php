<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\FolioAccountingService;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Read-only inventory of room-night accounting requiring no-show correction. */
final class NoShowRoomNightCorrectionPreview
{
    public function preview(Hotel $hotel, int $bookingId): array
    {
        return DB::transaction(function () use ($hotel, $bookingId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $hotelId = (int) $hotel->id;
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
            $booking = DB::table('reservation_records')->where('hotel_id', $hotelId)
                ->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking !== null, 404);
            abort_unless($booking->lifecycle_state === 'no_show', 409, 'Booking is not a no-show.');
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
            $assessments = DB::table('financial_folio_night_assessments')->where('hotel_id', $hotelId)
                ->where('folio_id', $folio->id)->orderBy('id')->get();
            $nights = [];
            $blockers = [];
            $totals = ['grossMinor' => 0, 'assessedVatMinor' => 0, 'recognizedRevenueMinor' => 0];
            foreach ($assessments as $assessment) {
                $issues = [];
                $journals = [];
                if ((int) $assessment->reservation_record_id !== $bookingId) {
                    $issues[] = 'assessment_booking_mismatch';
                }
                $entry = DB::table('folio_entries')->where('hotel_id', $hotelId)->where('folio_id', $folio->id)
                    ->where('id', $assessment->folio_entry_id)->first();
                if ($entry === null || $entry->entry_type !== 'room_night'
                    || (int) $entry->amount_minor !== (int) $assessment->gross_minor || $entry->stay_date !== $assessment->stay_date) {
                    $issues[] = 'room_entry_mismatch';
                }
                $gross = (int) $assessment->gross_minor;
                $vat = (int) $assessment->vat_minor;
                $credit = (int) $assessment->folio_credit_minor;
                $revenue = (int) $assessment->room_revenue_minor;
                $earned = 0;
                if ($assessment->status === 'approved') {
                    if ($gross <= 0 || min($vat, $credit, $revenue) < 0 || $gross !== $vat + $credit + $revenue) {
                        $issues[] = 'assessment_amount_mismatch';
                    }
                    $this->inspect($hotelId, $assessment->source_journal_id, 'folio_deferred',
                        'folio-entry-'.$assessment->folio_entry_id, 'AR', 'DEFERRED_ROOM_REV', $gross, $journals, $issues);
                    foreach ([
                        [$assessment->benefit_journal_id, 'folio_benefit', 'DEFERRED_ROOM_REV', 'AR', $credit],
                        [$assessment->vat_journal_id, 'vat_liability', 'DEFERRED_ROOM_REV', 'VAT_PAYABLE', $vat],
                    ] as [$id, $type, $debit, $creditAccount, $amount]) {
                        if ($amount > 0 || $id !== null) {
                            $this->inspect($hotelId, $id, $type, (string) $assessment->id,
                                $debit, $creditAccount, $amount, $journals, $issues);
                        }
                    }
                    if ($assessment->source_journal_id !== null) {
                        $recognitions = DB::table('financial_room_revenue_recognitions')->where('hotel_id', $hotelId)
                            ->where('source_journal_id', $assessment->source_journal_id)->orderBy('id')->get();
                        if ($recognitions->count() > 1) $issues[] = 'multiple_recognitions';
                        foreach ($recognitions as $recognition) {
                            $amount = (int) $recognition->amount_minor;
                            if ($recognition->stay_date !== $assessment->stay_date || $amount !== $revenue) {
                                $issues[] = 'recognition_amount_or_date_mismatch';
                            }
                            if ($this->inspect($hotelId, $recognition->recognition_journal_id, 'nightly_revenue',
                                $assessment->source_journal_id.'@'.$recognition->stay_date,
                                'DEFERRED_ROOM_REV', 'ROOM_REV', $amount, $journals, $issues)) {
                                $earned += $amount;
                            }
                        }
                    }
                    $totals['grossMinor'] += $gross;
                    $totals['assessedVatMinor'] += $vat;
                    $totals['recognizedRevenueMinor'] += $earned;
                } else {
                    $issues[] = 'unapproved_assessment_requires_review';
                    if ($assessment->source_journal_id !== null || $assessment->vat_journal_id !== null || $assessment->benefit_journal_id !== null) {
                        $issues[] = 'unapproved_assessment_has_journal';
                    }
                }
                if (DB::table('financial_folio_night_corrections')->where('hotel_id', $hotelId)
                    ->where('assessment_id', $assessment->id)->exists()) {
                    $issues[] = 'existing_tax_or_benefit_correction_requires_review';
                }
                if ($credit !== 0) $issues[] = 'existing_benefit_credit_requires_review';
                $nights[] = [
                    'assessmentId' => (string) $assessment->id, 'entryId' => (string) $assessment->folio_entry_id,
                    'stayDate' => $assessment->stay_date, 'status' => $assessment->status,
                    'grossMinor' => $gross, 'assessedVatMinor' => $vat, 'recognizedRevenueMinor' => $earned,
                    'journals' => $journals, 'issues' => array_values(array_unique($issues)),
                ];
            }
            foreach (['folio_settlement_signoffs', 'folio_settlement_allocations', 'folio_refund_requests', 'folio_adjustments'] as $table) {
                if (DB::table($table)->where('hotel_id', $hotelId)->where('folio_id', $folio->id)->exists()) {
                    $blockers[] = $table.'_requires_review';
                }
            }
            return [
                'bookingId' => (string) $bookingId, 'currency' => $folio->currency,
                'businessDate' => $clock?->business_date, 'assessmentCount' => count($nights),
                'requiresCorrection' => $nights !== [], 'nights' => $nights, 'totals' => $totals,
                'blockers' => $blockers, 'previewOnly' => true, 'canPost' => false,
                'scope' => 'linked_room_night_assessments_only',
            ];
        }, 3);
    }

    private function inspect(int $hotelId, mixed $id, string $type, string $reference, string $debit,
        string $credit, int $amount, array &$journals, array &$issues): bool
    {
        $row = $id === null ? null : DB::table('financial_posting_journals')->where('hotel_id', $hotelId)->where('id', $id)->first();
        if ($row === null) {
            $issues[] = $type.'_missing_journal';
            return false;
        }
        $reversed = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
            ->where('reverses_journal_id', $row->id)->exists();
        $lines = DB::table('financial_posting_lines')->where('journal_id', $row->id)->orderBy('line_number')->get();
        $valid = $row->currency === 'PHP' && $row->source_type === $type && $row->source_reference === $reference
            && $row->reverses_journal_id === null && $amount > 0 && $lines->count() === 2
            && $lines[0]->account_code === $debit && (int) $lines[0]->debit_minor === $amount
            && (int) $lines[0]->credit_minor === 0 && $lines[1]->account_code === $credit
            && (int) $lines[1]->credit_minor === $amount && (int) $lines[1]->debit_minor === 0;
        $journals[] = [
            'id' => (string) $row->id, 'kind' => $type, 'businessDate' => $row->business_date,
            'amountMinor' => $amount, 'reversed' => $reversed, 'matchesAssessment' => $valid,
            'closedDate' => DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                ->where('business_date', $row->business_date)->where('status', 'completed')->exists(),
        ];
        if (!$valid) $issues[] = $type.'_journal_mismatch';
        if ($reversed) $issues[] = $type.'_already_reversed';
        return $valid && !$reversed;
    }
}

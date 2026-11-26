<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Records correction intent and independent approval without posting any money. */
final class NoShowFeeCorrectionDecisionService
{
    public function propose(Hotel $hotel, int $bookingId, int $actorId, int $replacementMinor, string $reason): int
    {
        $this->enabled();
        $reason = $this->document($reason);
        return DB::transaction(function () use ($hotel, $bookingId, $actorId, $replacementMinor, $reason): int {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $snapshot = $this->snapshot($hotel, $bookingId, $actorId, $replacementMinor);
            $preview = $snapshot['preview'];
            abort_if(DB::table('no_show_fee_correction_proposals')->where('hotel_id', $hotel->id)
                ->where('clearance_id', $preview['clearanceId'])->exists(), 409, 'A fee correction proposal already exists.');
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
            return (int) DB::table('no_show_fee_correction_proposals')->insertGetId([
                'hotel_id' => $hotel->id, 'booking_id' => $bookingId, 'clearance_id' => $preview['clearanceId'],
                'proposed_by' => $actorId, 'original_fee_minor' => $preview['originalFeeMinor'],
                'replacement_fee_minor' => $replacementMinor, 'business_date' => $preview['businessDate'],
                'reason' => $reason, 'snapshot' => $json, 'snapshot_sha256' => hash('sha256', $json),
                'created_at' => now(),
            ]);
        }, 3);
    }

    public function approve(Hotel $hotel, int $proposalId, int $actorId, string $taxClassification, string $taxBasis, string $reason): int
    {
        $this->enabled();
        $reason = $this->document($reason);
        $taxBasis = $this->document($taxBasis);
        abort_unless(in_array($taxClassification, ['vat_standard', 'vat_zero', 'vat_exempt', 'non_vat'], true),
            422, 'A supported tax classification is required.');
        return DB::transaction(function () use ($hotel, $proposalId, $actorId, $taxClassification, $taxBasis, $reason): int {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $proposal = DB::table('no_show_fee_correction_proposals')->where('hotel_id', $hotel->id)
                ->where('id', $proposalId)->lockForUpdate()->first();
            abort_unless($proposal !== null, 404);
            abort_if((int) $proposal->proposed_by === $actorId, 403, 'Independent administrator required.');
            $snapshot = $this->snapshot($hotel, (int) $proposal->booking_id, $actorId, (int) $proposal->replacement_fee_minor);
            $preview = $snapshot['preview'];
            abort_unless((string) $proposal->clearance_id === $preview['clearanceId']
                && (int) $proposal->original_fee_minor === $preview['originalFeeMinor']
                && (string) $proposal->business_date === $preview['businessDate']
                && hash_equals($proposal->snapshot_sha256, hash('sha256', $proposal->snapshot))
                && hash_equals($proposal->snapshot_sha256, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))),
                409, 'Fee correction evidence changed; approval blocked.');
            abort_if(DB::table('no_show_fee_correction_reviews')->where('proposal_id', $proposalId)->exists(),
                409, 'Fee correction already approved.');
            $gross = (int) $proposal->replacement_fee_minor;
            $tax = app(PhilippineTaxCalculator::class)->calculate(
                $gross, $taxClassification, 'inclusive', $taxClassification === 'vat_standard' ? 1200 : 0
            );
            $vat = $tax['tax_minor'];
            abort_unless(is_int($vat) && $vat >= 0 && $vat <= $gross, 409, 'Invalid replacement tax calculation.');
            return (int) DB::table('no_show_fee_correction_reviews')->insertGetId([
                'proposal_id' => $proposalId, 'reviewed_by' => $actorId, 'tax_classification' => $taxClassification,
                'replacement_vat_minor' => $vat, 'replacement_revenue_minor' => $gross - $vat,
                'tax_basis' => $taxBasis, 'reason' => $reason, 'created_at' => now(),
            ]);
        }, 3);
    }

    private function snapshot(Hotel $hotel, int $bookingId, int $actorId, int $replacementMinor): array
    {
        $preview = app(NoShowFeeReversalPreview::class)->preview($hotel, $bookingId, $actorId, $replacementMinor);
        abort_unless($preview['eligibleForReview'] && $preview['blockers'] === [], 409,
            'Fee correction requires eligible, reconciled original accounting.');
        $hotelId = (int) $hotel->id;
        $folioId = DB::table('reservation_folios')->where('hotel_id', $hotelId)
            ->where('reservation_record_id', $bookingId)->value('id');
        $snapshot = ['preview' => $preview];
        foreach (['folio_entries', 'folio_adjustments', 'folio_payment_records', 'folio_settlement_verifications',
            'folio_settlement_allocations', 'folio_refund_requests'] as $table) {
            $snapshot[$table] = DB::table($table)->where('hotel_id', $hotelId)->where('folio_id', $folioId)
                ->orderBy('id')->get()->all();
        }
        $snapshot['review'] = DB::table('no_show_financial_reviews')->where('hotel_id', $hotelId)
            ->where('id', $preview['reviewId'])->first();
        $snapshot['clearance'] = DB::table('no_show_financial_clearances')->where('hotel_id', $hotelId)
            ->where('id', $preview['clearanceId'])->first();
        $snapshot['journal'] = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
            ->where('id', $preview['originalJournalId'])->first();
        $snapshot['journalLines'] = DB::table('financial_posting_lines')->where('journal_id', $preview['originalJournalId'])
            ->orderBy('line_number')->get()->all();
        return $snapshot;
    }

    private function enabled(): void
    {
        abort_unless(config('financial_accounting.enable_no_show_fee_correction_review', false),
            409, 'Fee correction review is disabled.');
    }

    private function document(string $reason): string
    {
        $reason = trim($reason);
        abort_unless(mb_strlen($reason) >= 15 && mb_strlen($reason) <= 1000, 422, 'Document the correction and tax basis.');
        return $reason;
    }
}

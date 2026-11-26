<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A corrected clearance is derived from immutable proposal, approval and postings. */
final class NoShowEffectiveFeeClearance
{
    public function appliedProposal(int $hotelId, int $bookingId): ?object
    {
        if (!Schema::hasTable('no_show_fee_correction_proposals')) return null;
        return DB::table('no_show_fee_correction_proposals as p')
            ->where('p.hotel_id', $hotelId)->where('p.booking_id', $bookingId)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('financial_posting_journals as j')
                ->whereColumn('j.hotel_id', 'p.hotel_id')->where('j.source_type', 'no_show_fee_reversal')
                ->whereRaw(DB::getDriverName() === 'sqlite'
                    ? "j.source_reference = 'proposal-' || p.id" : "j.source_reference = CONCAT('proposal-', p.id)"))
            ->orderByDesc('p.id')->first(['p.*']);
    }

    public function resolve(int $hotelId, int $bookingId, int $folioId, object $p): ?object
    {
        if ((int) $p->hotel_id !== $hotelId || (int) $p->booking_id !== $bookingId
            || !hash_equals($p->snapshot_sha256, hash('sha256', $p->snapshot))) return null;
        $s = json_decode($p->snapshot, true);
        $preview = $s['preview'] ?? [];
        $a = DB::table('no_show_fee_correction_reviews')->where('proposal_id', $p->id)->first();
        $original = DB::table('no_show_financial_clearances')->where('hotel_id', $hotelId)->where('id', $p->clearance_id)->first();
        if ($a === null || $original === null || (int) $a->reviewed_by === (int) $p->proposed_by
            || (string) $p->clearance_id !== ($preview['clearanceId'] ?? null)
            || (string) $bookingId !== ($preview['bookingId'] ?? null)
            || (int) $p->original_fee_minor !== ($preview['originalFeeMinor'] ?? null)
            || (int) $p->replacement_fee_minor !== ($preview['replacementFeeMinor'] ?? null)
            || (string) $original->journal_id !== ($preview['originalJournalId'] ?? null)
            || json_encode($original) !== json_encode($s['clearance'] ?? null)) return null;
        $review = DB::table('no_show_financial_reviews')->where('hotel_id', $hotelId)->where('id', $original->review_id)
            ->where('folio_id', $folioId)->where('reservation_record_id', $bookingId)->first();
        if ($review === null || json_encode($review) !== json_encode($s['review'] ?? null)) return null;
        $gross = (int) $p->replacement_fee_minor;
        $vat = (int) $a->replacement_vat_minor;
        $revenue = (int) $a->replacement_revenue_minor;
        $credit = (int) $p->original_fee_minor - $gross;
        if ($credit <= 0 || min($gross, $vat, $revenue) < 0 || $vat + $revenue !== $gross) return null;
        $originalJournal = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)->where('id', $original->journal_id)->first();
        if ($originalJournal === null || json_encode($originalJournal) !== json_encode($s['journal'] ?? null)) return null;
        $originalLines = DB::table('financial_posting_lines')->where('journal_id', $originalJournal->id)->orderBy('line_number')->get()->all();
        if (json_encode($originalLines) !== json_encode($s['journalLines'] ?? null)) return null;
        $reverse = $this->journal($hotelId, $p, $a, 'no_show_fee_reversal', 'no-show-fee-reversal-', $preview['proposedReversalLines'] ?? []);
        if ($reverse === null || (int) $reverse->reverses_journal_id !== (int) $originalJournal->id
            || DB::table('financial_posting_journals')->where('hotel_id', $hotelId)->where('reverses_journal_id', $originalJournal->id)->count() !== 1) return null;
        $replacement = null;
        $lines = [['account_code' => 'AR', 'debit_minor' => $gross, 'credit_minor' => 0]];
        if ($revenue > 0) $lines[] = ['account_code' => 'NO_SHOW_REV', 'debit_minor' => 0, 'credit_minor' => $revenue];
        if ($vat > 0) $lines[] = ['account_code' => 'VAT_PAYABLE', 'debit_minor' => 0, 'credit_minor' => $vat];
        if ($gross > 0) {
            $replacement = $this->journal($hotelId, $p, $a, 'no_show_fee_replacement', 'no-show-fee-replacement-', $lines);
            if ($replacement === null || $replacement->reverses_journal_id !== null) return null;
        } elseif (DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
            ->where('source_type', 'no_show_fee_replacement')->where('source_reference', 'proposal-'.$p->id)->exists()) return null;
        $adjustments = DB::table('folio_adjustments')->where('hotel_id', $hotelId)->where('folio_id', $folioId)->orderBy('id')->get();
        $adjustment = $adjustments->firstWhere('request_key', 'no-show-fee-correction-'.$p->id);
        if ($adjustment === null || $adjustment->kind !== 'credit' || (int) $adjustment->amount_minor !== $credit
            || (int) $adjustment->actor_id !== (int) $a->reviewed_by
            || !hash_equals($adjustment->payload_sha256, hash('sha256', json_encode([
                (int) $p->id, $credit, (int) $reverse->id, $replacement === null ? null : (int) $replacement->id,
            ], JSON_THROW_ON_ERROR)))) return null;
        if (json_encode($adjustments->reject(fn ($row) => $row->id === $adjustment->id)->values()->all()) !== json_encode($s['folio_adjustments'] ?? null)
            || json_encode(DB::table('folio_entries')->where('hotel_id', $hotelId)->where('folio_id', $folioId)->orderBy('id')->get()->all()) !== json_encode($s['folio_entries'] ?? null)) return null;
        return (object) [
            'id' => $original->id, 'review_id' => $review->id, 'hotel_id' => $hotelId,
            'gross_minor' => $gross, 'vat_minor' => $vat, 'revenue_minor' => $revenue,
            'journal_id' => $replacement?->id, 'reviewed_by' => $a->reviewed_by,
            'business_date' => $p->business_date, 'correction_proposal_id' => $p->id,
            'reversal_journal_id' => $reverse->id, 'folio_adjustment_id' => $adjustment->id,
        ];
    }

    private function journal(int $hotelId, object $p, object $a, string $type, string $key, array $expected): ?object
    {
        $rows = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
            ->where('source_type', $type)->where('source_reference', 'proposal-'.$p->id)->get();
        if ($rows->count() !== 1 || count($expected) < 2) return null;
        $j = $rows->first();
        if ($j->currency !== 'PHP' || $j->idempotency_key !== $key.$p->id
            || (int) $j->posted_by !== (int) $a->reviewed_by || (string) $j->business_date !== (string) $p->business_date
            || DB::table('financial_posting_journals')->where('hotel_id', $hotelId)->where('reverses_journal_id', $j->id)->exists()) return null;
        $lines = DB::table('financial_posting_lines')->where('journal_id', $j->id)->orderBy('line_number')->get()
            ->map(fn ($l): array => ['account_code' => $l->account_code, 'debit_minor' => (int) $l->debit_minor,
                'credit_minor' => (int) $l->credit_minor])->all();
        return $lines === $expected ? $j : null;
    }
}

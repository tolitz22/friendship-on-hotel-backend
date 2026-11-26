<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/** Verify completed, independently approved reversals without writing journals. */
final class NoShowRoomCorrectionCompletion
{
    public function valid(int $hotelId, int $folioId): bool
    {
        $folio = DB::table('reservation_folios')->where('hotel_id', $hotelId)->where('id', $folioId)->first();
        if ($folio === null || $folio->currency !== 'PHP') return false;
        $bookingId = (int) $folio->reservation_record_id;
        if (!DB::table('reservation_records')->where('hotel_id', $hotelId)->where('id', $bookingId)
            ->where('lifecycle_state', 'no_show')->exists()) return false;
        $proposal = DB::table('no_show_room_correction_proposals')->where('hotel_id', $hotelId)
            ->where('booking_id', $bookingId)->first();
        if ($proposal === null || !hash_equals($proposal->snapshot_sha256, hash('sha256', $proposal->snapshot))) return false;
        $approval = DB::table('no_show_room_correction_reviews')->where('proposal_id', $proposal->id)->first();
        if ($approval === null || (int) $approval->reviewed_by === (int) $proposal->proposed_by) return false;
        $snapshot = json_decode($proposal->snapshot, true);
        if (!is_array($snapshot) || ($snapshot['bookingId'] ?? null) !== (string) $bookingId
            || ($snapshot['currency'] ?? null) !== 'PHP' || ($snapshot['blockers'] ?? null) !== []
            || empty($snapshot['journalRecords']) || empty($snapshot['assessments']) || empty($snapshot['folioEntries'])) return false;
        $assessments = DB::table('financial_folio_night_assessments')->where('hotel_id', $hotelId)
            ->where('folio_id', $folioId)->orderBy('id')->get()->all();
        $entries = DB::table('folio_entries')->where('hotel_id', $hotelId)->where('folio_id', $folioId)
            ->orderBy('id')->get()->all();
        if (json_encode($assessments) !== json_encode($snapshot['assessments'])
            || json_encode($entries) !== json_encode($snapshot['folioEntries'])) return false;
        foreach ($assessments as $assessment) {
            if (DB::table('financial_folio_night_corrections')->where('hotel_id', $hotelId)
                ->where('assessment_id', $assessment->id)->exists()) return false;
        }
        $originals = $snapshot['journalRecords'];
        $reversals = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
            ->where('source_type', 'no_show_room_correction')->where('source_reference', 'proposal-'.$proposal->id)->get();
        if ($reversals->count() !== count($originals)) return false;
        $ids = [];
        foreach ($originals as $original) {
            $id = (int) $original['id'];
            if (isset($ids[$id])) return false;
            $ids[$id] = true;
            $source = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)->where('id', $id)->first();
            if ($source === null || json_encode($source) !== json_encode($original)) return false;
            $matches = $reversals->filter(fn ($row): bool => (int) $row->reverses_journal_id === $id);
            if ($matches->count() !== 1) return false;
            $reverse = $matches->first();
            if ($reverse->currency !== 'PHP' || (int) $reverse->posted_by !== (int) $approval->reviewed_by
                || $reverse->idempotency_key !== 'no-show-room-reversal-'.$proposal->id.'-'.$id
                || DB::table('financial_posting_journals')->where('hotel_id', $hotelId)->where('reverses_journal_id', $reverse->id)->exists()
                || DB::table('financial_posting_journals')->where('hotel_id', $hotelId)->where('reverses_journal_id', $id)->count() !== 1) return false;
            $approvedLines = array_values(array_filter($snapshot['journalLines'], fn (array $line): bool => (int) $line['journal_id'] === $id));
            $lines = DB::table('financial_posting_lines')->where('journal_id', $id)->orderBy('line_number')->get()->all();
            $reverseLines = DB::table('financial_posting_lines')->where('journal_id', $reverse->id)->orderBy('line_number')->get();
            if (count($lines) < 2 || json_encode($lines) !== json_encode($approvedLines) || $reverseLines->count() !== count($lines)) return false;
            foreach ($lines as $i => $line) {
                $r = $reverseLines[$i];
                if ($r->account_code !== $line->account_code || (int) $r->debit_minor !== (int) $line->credit_minor
                    || (int) $r->credit_minor !== (int) $line->debit_minor) return false;
            }
        }
        return true;
    }
}

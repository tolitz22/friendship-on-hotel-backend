<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Current, booking-scoped correction history; never a historical closing certificate. */
final class NoShowCorrectionJournalHistory
{
    public function report(Hotel $hotel, int $bookingId, int $actorId): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actorId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $hotelId = (int) $hotel->id;
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotelId)
                ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
            $booking = DB::table('reservation_records')->where('hotel_id', $hotelId)->where('id', $bookingId)->first();
            abort_unless($booking !== null, 404);
            abort_unless($booking->lifecycle_state === 'no_show', 409, 'Booking must remain a no-show.');
            $folio = DB::table('reservation_folios')->where('hotel_id', $hotelId)
                ->where('reservation_record_id', $bookingId)->first();
            abort_unless($folio !== null && $folio->currency === 'PHP', 409, 'PHP folio required.');
            $groups = [];
            $movements = [];
            foreach (['room', 'fee'] as $kind) {
                $prefix = 'no_show_'.$kind.'_correction_';
                $proposals = DB::table($prefix.'proposals')->where('hotel_id', $hotelId)
                    ->where('booking_id', $bookingId)->orderBy('id')->get();
                foreach ($proposals as $proposal) {
                    $approval = DB::table($prefix.'reviews')->where('proposal_id', $proposal->id)->first();
                    $types = $kind === 'room' ? ['no_show_room_correction'] : ['no_show_fee_reversal', 'no_show_fee_replacement'];
                    $rows = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                        ->where('source_reference', 'proposal-'.$proposal->id)->whereIn('source_type', $types)
                        ->orderBy('business_date')->orderBy('id')->get();
                    $valid = !$rows->isEmpty() && ($kind === 'room'
                        ? app(NoShowRoomCorrectionCompletion::class)->valid($hotelId, (int) $folio->id)
                        : app(NoShowEffectiveFeeClearance::class)->resolve($hotelId, $bookingId, (int) $folio->id, $proposal) !== null);
                    $journalHistory = [];
                    foreach ($rows as $row) {
                        $entry = $this->journal($hotelId, $row);
                        $journalHistory[] = $entry;
                        foreach ($entry['lines'] as $line) {
                            $key = $line['accountCode'];
                            $movements[$key] ??= ['accountCode' => $key, 'debitMinor' => 0, 'creditMinor' => 0];
                            $movements[$key]['debitMinor'] += $line['debitMinor'];
                            $movements[$key]['creditMinor'] += $line['creditMinor'];
                        }
                    }
                    $snapshotValid = hash_equals($proposal->snapshot_sha256, hash('sha256', $proposal->snapshot));
                    $groups[] = [
                        'kind' => $kind, 'proposalId' => (string) $proposal->id,
                        'businessDate' => $proposal->business_date, 'proposedBy' => (string) $proposal->proposed_by,
                        'proposedAt' => $proposal->created_at, 'reason' => $proposal->reason,
                        'reviewId' => $approval === null ? null : (string) $approval->id,
                        'reviewedBy' => $approval === null ? null : (string) $approval->reviewed_by,
                        'reviewedAt' => $approval?->created_at, 'reviewReason' => $approval?->reason,
                        'snapshotIntegrityValid' => $snapshotValid,
                        'status' => !$rows->isEmpty() ? ($valid ? 'posted_verified' : 'posted_requires_reconciliation')
                            : (!$snapshotValid ? 'invalid_snapshot' : ($approval === null ? 'awaiting_review' : 'approved_unposted')),
                        'originalFeeMinor' => $kind === 'fee' ? (int) $proposal->original_fee_minor : null,
                        'replacementFeeMinor' => $kind === 'fee' ? (int) $proposal->replacement_fee_minor : null,
                        'journals' => $journalHistory,
                    ];
                }
            }
            ksort($movements);
            return [
                'bookingId' => (string) $bookingId, 'currency' => 'PHP', 'businessDate' => $clock?->business_date,
                'corrections' => $groups, 'postedCorrectionMovements' => array_values($movements),
                'scope' => 'linked_correction_journals_only', 'readOnly' => true, 'closingCertified' => false,
                'orphanDetectionIncluded' => false,
            ];
        }, 3);
    }

    private function journal(int $hotelId, object $row): array
    {
        $lines = DB::table('financial_posting_lines')->where('journal_id', $row->id)->orderBy('line_number')->get()
            ->map(fn ($line): array => [
                'accountCode' => $line->account_code, 'debitMinor' => (int) $line->debit_minor,
                'creditMinor' => (int) $line->credit_minor,
            ])->all();
        $original = $row->reverses_journal_id === null ? null : DB::table('financial_posting_journals')
            ->where('hotel_id', $hotelId)->where('id', $row->reverses_journal_id)->first();
        return [
            'journalId' => (string) $row->id, 'sourceType' => $row->source_type,
            'businessDate' => $row->business_date, 'currency' => $row->currency,
            'postedBy' => $row->posted_by === null ? null : (string) $row->posted_by,
            'postedAt' => $row->posted_at,
            'originalJournalId' => $row->reverses_journal_id === null ? null : (string) $row->reverses_journal_id,
            'originalSourceType' => $original?->source_type, 'originalBusinessDate' => $original?->business_date,
            'originalJournalFound' => $row->reverses_journal_id === null ? null : $original !== null,
            'subsequentlyReversed' => DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                ->where('reverses_journal_id', $row->id)->exists(),
            'businessDateClosed' => DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                ->where('business_date', $row->business_date)->where('status', 'completed')->exists(),
            'lines' => $lines,
        ];
    }
}

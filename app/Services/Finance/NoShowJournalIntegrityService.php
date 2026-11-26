<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Property-wide, read-only inspection of the staged no-show journal sources. */
final class NoShowJournalIntegrityService
{
    private const SOURCES = [
        'guest_deposit_receipt' => ['folio-payment-', 'folio_payment_records'],
        'guest_deposit_opening_reclassification' => ['folio-payment-', 'folio_payment_records'],
        'no_show_fee' => ['no-show-review-', 'no_show_financial_reviews'],
        'no_show_deposit_allocation' => ['folio-allocation-', 'folio_settlement_allocations'],
        'no_show_deposit_refund' => ['refund-evidence-', 'folio_refund_disbursement_evidence'],
        'no_show_room_correction' => ['proposal-', 'no_show_room_correction_proposals'],
        'no_show_fee_reversal' => ['proposal-', 'no_show_fee_correction_proposals'],
        'no_show_fee_replacement' => ['proposal-', 'no_show_fee_correction_proposals'],
    ];

    public function report(Hotel $hotel): array
    {
        return DB::transaction(function () use ($hotel): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $hotelId = (int) $hotel->id;
            DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
            $issues = [];
            $seen = [];
            $count = 0;
            foreach (DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                ->whereIn('source_type', array_keys(self::SOURCES))->lazyById(500) as $journal) {
                $count++;
                [$prefix, $table] = self::SOURCES[$journal->source_type];
                $reference = $journal->source_reference;
                $sourceId = self::sourceId($prefix, $reference);
                $source = $sourceId === null ? null : DB::table($table)
                    ->where('hotel_id', $hotelId)->where('id', $sourceId)->first();
                $issue = static function (string $reason, array $extra = []) use (&$issues, $journal): void {
                    $issues[] = ['reason' => $reason, 'journalId' => (string) $journal->id,
                        'sourceType' => $journal->source_type, 'sourceReference' => $journal->source_reference] + $extra;
                };
                if ($source === null) {
                    $issue($sourceId === null ? 'invalid_source_reference' : 'orphaned_journal');
                }
                $original = $journal->reverses_journal_id === null ? null : DB::table('financial_posting_journals')
                    ->where('hotel_id', $hotelId)->where('id', $journal->reverses_journal_id)->first();
                $requiresReversal = in_array($journal->source_type, ['no_show_room_correction', 'no_show_fee_reversal'], true);
                if (($requiresReversal && $original === null) || ($journal->reverses_journal_id !== null && $original === null)) {
                    $issue('orphaned_reversal');
                }
                if ($requiresReversal && $source !== null) {
                    $kind = $journal->source_type === 'no_show_room_correction' ? 'room' : 'fee';
                    if (!DB::table('no_show_'.$kind.'_correction_reviews')->where('proposal_id', $sourceId)->exists()) {
                        $issue('correction_review_missing');
                    }
                }
                if ($journal->source_type === 'no_show_room_correction' && $source !== null) {
                    $snapshot = json_decode($source->snapshot, true);
                    $originalIds = is_array($snapshot['journalRecords'] ?? null)
                        ? array_map(static fn ($row): string => (string) ($row['id'] ?? ''), $snapshot['journalRecords']) : [];
                    if (!hash_equals($source->snapshot_sha256, hash('sha256', $source->snapshot))
                        || !in_array((string) $journal->reverses_journal_id, $originalIds, true)) {
                        $issue('correction_original_not_in_snapshot');
                    }
                }
                $key = self::duplicateKey($journal->source_type, $reference, $journal->reverses_journal_id);
                if (isset($seen[$key])) {
                    $issue('duplicate_journal', ['firstJournalId' => $seen[$key]]);
                } else {
                    $seen[$key] = (string) $journal->id;
                }
                if ($journal->reverses_journal_id !== null && DB::table('financial_posting_journals')
                    ->where('hotel_id', $hotelId)->where('reverses_journal_id', $journal->reverses_journal_id)
                    ->where('id', '<', $journal->id)->exists()) {
                    $issue('duplicate_reversal');
                }
            }
            return ['hotelId' => (string) $hotelId, 'scope' => 'no_show_journal_sources',
                'scannedJournalCount' => $count, 'issues' => $issues, 'integrityValid' => $issues === [],
                'readOnly' => true, 'closingCertified' => false];
        }, 3);
    }

    public static function sourceId(string $prefix, string $reference): ?string
    {
        if (!str_starts_with($reference, $prefix)) {
            return null;
        }
        $id = substr($reference, strlen($prefix));
        return preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1
            && (strlen($id) < 19 || strcmp($id, '9223372036854775807') <= 0) ? $id : null;
    }

    public static function duplicateKey(string $type, string $reference, int|string|null $originalId): string
    {
        if (in_array($type, ['guest_deposit_receipt', 'guest_deposit_opening_reclassification'], true)) {
            $type = 'guest_deposit';
        }
        // A room proposal may legitimately reverse several different original journals.
        return $type.':'.$reference.($type === 'no_show_room_correction' ? ':'.$originalId : '');
    }
}

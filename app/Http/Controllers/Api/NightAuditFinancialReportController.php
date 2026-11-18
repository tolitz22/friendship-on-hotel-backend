<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 62: read-only financial reconciliation report.
 *
 * Never infer collected cash from staff-confirmed payment records or infer
 * physical occupancy from a scheduled reservation. Historical occupancy,
 * ADR and RevPAR must remain unavailable until independent stay evidence
 * and net recognized room revenue have been reconciled.
 */
final class NightAuditFinancialReportController
{
    public function __invoke(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $date = $input['date'];

        $review = DB::table('night_audit_reviews')
            ->where('hotel_id', $hotel->id)
            ->where('business_date', $date)
            ->first(['status', 'reviewed_at', 'snapshot_json', 'snapshot_sha256']);

        $close = DB::table('financial_day_closes')
            ->where('hotel_id', $hotel->id)
            ->where('business_date', $date)
            ->first(['status', 'closed_at', 'reconciliation_snapshot']);

        $reviewValid = $review !== null
            && hash_equals((string) $review->snapshot_sha256, hash('sha256', (string) $review->snapshot_json));
        $reviewSnapshot = $reviewValid
            ? json_decode((string) $review->snapshot_json, true, 512, JSON_THROW_ON_ERROR)
            : null;
        $closeSnapshot = $close !== null
            ? json_decode((string) $close->reconciliation_snapshot, true, 512, JSON_THROW_ON_ERROR)
            : null;

        // Closed-day journals are immutable. Rechecking totals here detects
        // discrepancies between the close snapshot and the underlying ledger.
        $journalIds = DB::table('financial_posting_journals')
            ->where('hotel_id', $hotel->id)
            ->where('business_date', $date)
            ->pluck('id');
        $totals = ['debitsMinor' => 0, 'creditsMinor' => 0, 'lineCount' => 0];
        $unbalanced = 0;
        foreach ($journalIds->chunk(500) as $chunk) {
            $byJournal = [];
            foreach (DB::table('financial_posting_lines')
                ->whereIn('journal_id', $chunk->all())
                ->get(['journal_id', 'debit_minor', 'credit_minor']) as $line) {
                $id = (int) $line->journal_id;
                $byJournal[$id] ??= ['debit' => 0, 'credit' => 0, 'count' => 0, 'invalid' => false];
                $debit = (int) $line->debit_minor;
                $credit = (int) $line->credit_minor;
                if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)
                    || $debit > PHP_INT_MAX - $totals['debitsMinor']
                    || $credit > PHP_INT_MAX - $totals['creditsMinor']) {
                    $byJournal[$id]['invalid'] = true;
                    continue;
                }
                $byJournal[$id]['debit'] += $debit;
                $byJournal[$id]['credit'] += $credit;
                $byJournal[$id]['count']++;
                $totals['debitsMinor'] += $debit;
                $totals['creditsMinor'] += $credit;
                $totals['lineCount']++;
            }
            foreach ($chunk as $id) {
                $journal = $byJournal[(int) $id] ?? null;
                if ($journal === null || $journal['invalid']
                    || $journal['count'] < 2 || $journal['debit'] !== $journal['credit']) {
                    $unbalanced++;
                }
            }
        }
        $snapshotMatches = $closeSnapshot !== null
            && (int) ($closeSnapshot['journal_count'] ?? -1) === $journalIds->count()
            && (int) ($closeSnapshot['line_count'] ?? -1) === $totals['lineCount']
            && (int) ($closeSnapshot['debits_minor'] ?? -1) === $totals['debitsMinor']
            && (int) ($closeSnapshot['credits_minor'] ?? -1) === $totals['creditsMinor'];

        $blockers = [];
        if (!$reviewValid) $blockers[] = 'Night-audit snapshot is missing or fails its integrity check.';
        if ($review !== null && $review->status !== 'reviewed') $blockers[] = 'Night audit was flagged, not cleared.';
        if ($close === null || $close->status !== 'completed') $blockers[] = 'Financial business date has not been closed.';
        if ($unbalanced !== 0 || $totals['debitsMinor'] !== $totals['creditsMinor']) {
            $blockers[] = 'Posting journals contain invalid or unbalanced lines.';
        }
        if ($close !== null && !$snapshotMatches) {
            $blockers[] = 'Financial close totals do not match the current posting ledger.';
        }

        return response()->json(['data' => [
            'hotelId' => (string) $hotel->id,
            'businessDate' => $date,
            'currency' => (string) ($closeSnapshot['currency'] ?? $hotel->currency ?? 'PHP'),
            'nightAudit' => [
                'status' => $review?->status ?? 'missing',
                'reviewedAt' => $review?->reviewed_at,
                'snapshotIntegrityValid' => $reviewValid,
                'scheduledRoomNights' => $reviewSnapshot['scheduledRoomNights'] ?? null,
                'pricedRoomNights' => $reviewSnapshot['pricedRoomNights'] ?? null,
                'roomCount' => $reviewSnapshot['roomCount'] ?? null,
                'postedRoomChargesMinor' => $reviewSnapshot['postedRoomChargesMinor'] ?? null,
            ],
            'financialClose' => [
                'status' => $close?->status ?? 'missing',
                'closedAt' => $close?->closed_at,
                'journalCount' => $journalIds->count(),
                'lineCount' => $totals['lineCount'],
                'debitsMinor' => $totals['debitsMinor'],
                'creditsMinor' => $totals['creditsMinor'],
                'unbalancedJournalCount' => $unbalanced,
                'snapshotMatchesLedger' => $snapshotMatches,
            ],
            'kpis' => [
                'verifiedOccupiedRoomNights' => null,
                'recognizedRoomRevenueMinor' => null,
                'adrMinor' => null,
                'revparMinor' => null,
                'occupancyPercent' => null,
            ],
            'limitations' => [
                'Scheduled room nights are not independently verified occupied room nights.',
                'Posted charges and balanced journal totals are not themselves earned room revenue or collected cash.',
                'ADR, RevPAR, occupancy and revenue KPIs remain null until reconciliation to independently verified occupancy and recognized revenue is implemented.',
                'No bank or gateway settlement confirmation is implied.',
            ],
            'blockers' => $blockers,
            'financialJournalReconciled' => $blockers === [],
            'reportScope' => 'phase_62_financial_journal_reconciliation_foundation',
        ]]);
    }
}

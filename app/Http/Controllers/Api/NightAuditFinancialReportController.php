<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Carbon\CarbonImmutable;
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

        // These values are derived from posted double-entry accounting lines,
        // not reservation quotes or recorded payment evidence. Revenue and VAT
        // reversals are reflected as debit movements on the same accounts.
        $accounts = DB::table('financial_posting_journals as j')
            ->join('financial_posting_lines as l', 'l.journal_id', '=', 'j.id')
            ->where('j.hotel_id', $hotel->id)
            ->where('j.business_date', $date)
            ->where(function ($query): void {
                $query->where(function ($room): void {
                    $room->where('l.account_code', 'ROOM_REV')
                        ->whereIn('j.source_type', [
                            'nightly_revenue', 'nightly_revenue_reversal',
                            'folio_tax_correction',
                        ]);
                })->orWhere(function ($vat): void {
                    $vat->where('l.account_code', 'VAT_PAYABLE')
                        ->whereIn('j.source_type', [
                            'vat_liability', 'folio_tax_correction',
                        ]);
                });
            })
            ->selectRaw('l.account_code, SUM(l.credit_minor) as credits, SUM(l.debit_minor) as debits')
            ->groupBy('l.account_code')->get()->keyBy('account_code');
        $roomRevenue = (int) ($accounts->get('ROOM_REV')?->credits ?? 0)
            - (int) ($accounts->get('ROOM_REV')?->debits ?? 0);
        $vatLiability = (int) ($accounts->get('VAT_PAYABLE')?->credits ?? 0)
            - (int) ($accounts->get('VAT_PAYABLE')?->debits ?? 0);

        // One independently checked room observation per room and day.
        // An uninspected room is UNKNOWN, never assumed vacant.
        $rooms = DB::table('rooms')->where('hotel_id', $hotel->id)->count();
        $observations = DB::table('occupancy_verifications')
            ->where('hotel_id', $hotel->id)->where('inspection_date', $date)
            ->get(['room_id', 'status', 'observed_occupancy', 'discrepancy']);
        $verified = $observations->filter(fn ($row) => $row->status === 'verified'
            && $row->discrepancy === 'match'
            && in_array($row->observed_occupancy, ['occupied', 'vacant'], true));
        $covered = $rooms > 0 && $observations->count() === $rooms
            && $verified->count() === $rooms
            && $verified->pluck('room_id')->unique()->count() === $rooms;
        $occupied = $covered
            ? $verified->where('observed_occupancy', 'occupied')->count() : null;

        // Use the submitted date, not the date the shift was first opened.
        // A flagged shift is never reported as verified collections.
        $zone = $hotel->timezone ?: 'Asia/Manila';
        $shiftFrom = CarbonImmutable::parse($date, $zone)->startOfDay()->utc();
        $shiftUntil = CarbonImmutable::parse($date, $zone)->addDay()->startOfDay()->utc();
        $shifts = DB::table('cashier_shifts')->where('hotel_id', $hotel->id)
            ->where('submitted_at', '>=', $shiftFrom->toDateTimeString())
            ->where('submitted_at', '<', $shiftUntil->toDateTimeString())
            ->get(['status', 'expected_cash_minor', 'counted_cash_minor', 'variance_minor']);
        $cashier = [
            'submittedCount' => $shifts->count(),
            'reconciledCount' => $shifts->where('status', 'reconciled')->count(),
            'flaggedCount' => $shifts->where('status', 'flagged')->count(),
            'pendingCount' => $shifts->where('status', 'submitted')->count(),
            'varianceMinor' => (int) $shifts->sum(fn ($row) => (int) ($row->variance_minor ?? 0)),
            'reconciledCountedCashMinor' => (int) $shifts->where('status', 'reconciled')
                ->sum(fn ($row) => (int) ($row->counted_cash_minor ?? 0)),
            'scope' => 'staff_counted_cash_independently_reconciled_not_bank_deposit',
        ];

        // Expenses are accounting-date movements, not deductions from room revenue.
        $expenseAccounts = DB::table('financial_posting_journals as j')
            ->join('financial_posting_lines as l', 'l.journal_id', '=', 'j.id')
            ->where('j.hotel_id', $hotel->id)->where('j.business_date', $date)
            ->whereIn('j.source_type', ['cash_expense', 'cash_expense_reversal'])
            ->where('l.account_code', 'operating_expense')
            ->selectRaw('COALESCE(SUM(l.debit_minor), 0) as debits, COALESCE(SUM(l.credit_minor), 0) as credits')
            ->first();
        $expenses = [
            'postedExpenseMinor' => (int) $expenseAccounts->debits,
            'reversedExpenseMinor' => (int) $expenseAccounts->credits,
            'netExpenseMinor' => (int) $expenseAccounts->debits - (int) $expenseAccounts->credits,
            'scope' => 'cash_expense_journal_movements_by_business_date',
            'closedLedgerVerified' => $close !== null && $close->status === 'completed'
                && $snapshotMatches && $unbalanced === 0,
        ];

        $blockers = [];
        $accountingReconciled = $reviewValid && $review?->status === 'reviewed'
            && $close !== null && $close->status === 'completed'
            && $unbalanced === 0
            && $totals['debitsMinor'] === $totals['creditsMinor']
            && $snapshotMatches;
        if (!$covered) $blockers[] = 'Independent occupancy observations do not cover every current room on this date.';
        if ($cashier['flaggedCount'] > 0 || $cashier['pendingCount'] > 0) {
            $blockers[] = 'Cashier exceptions still require manager investigation.';
        }
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
            'folioTaxAccountingEnabled' => (bool) config('financial_accounting.enable_live_folio_tax', false),
            'kpis' => [
                // Observations reflect an independent dated inspection, not
                // continuous overnight hotel occupancy. These are deliberately
                // labelled inspection proxies instead of certified KPIs.
                'verifiedOccupiedRoomNights' => null,
                'recognizedRoomRevenueMinor' => $close !== null && $snapshotMatches && $unbalanced === 0 ? $roomRevenue : null,
                'vatLiabilityMovementMinor' => $close !== null && $snapshotMatches && $unbalanced === 0 ? $vatLiability : null,
                'adrMinor' => null,
                'revparMinor' => null,
                'occupancyPercent' => null,
                'inspectionOccupiedRooms' => $occupied,
                'inspectionOccupancyPercent' => $covered ? round($occupied * 100 / $rooms, 2) : null,
                'inspectionRoomCount' => $rooms,
                'inspectionCoverageComplete' => $covered,
            ],
            'cashier' => $cashier,
            'expenses' => $expenses,
            'limitations' => [
                'Scheduled room nights are not independently verified occupied room nights.',
                'Point-in-time verified room inspections cannot establish overnight room-night occupancy; official ADR/RevPAR remain unavailable.',
                'Posted charges and balanced journal totals are not themselves earned room revenue or collected cash.',
                'Revenue is recognized ROOM_REV account movement on the selected accounting date; it can include corrections to prior stays. Room-night ADR and RevPAR require historical occupied room nights and stay-date attribution.',
                'No bank or gateway settlement confirmation is implied.',
            ],
            'blockers' => $blockers,
            'financialJournalReconciled' => $accountingReconciled,
            'allReportChecksClear' => $blockers === [],
            'reportScope' => 'phase_62_reconciled_journals_cashier_and_inspection_evidence',
        ]]);
    }
}

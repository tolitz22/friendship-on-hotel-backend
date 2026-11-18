<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\FinancialBusinessDayCloseService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrator-only financial day close. The immutable operational night-audit
 * review must be cleared before the financial business clock can advance.
 */
final class FinancialBusinessDayController
{
    public function show(Hotel $hotel): JsonResponse
    {
        $clock = DB::table('financial_business_clocks')
            ->where('hotel_id', $hotel->id)->first();

        if ($clock === null) {
            return response()->json(['message' => 'Financial business clock is not configured.'], 409);
        }

        $date = CarbonImmutable::parse($clock->business_date)->toDateString();
        $review = DB::table('night_audit_reviews')
            ->where('hotel_id', $hotel->id)->where('business_date', $date)
            ->first(['status', 'reviewed_by', 'reviewed_at']);

        $blockers = [];
        if ($clock->status !== 'open') {
            $blockers[] = 'Financial business clock is not open.';
        }
        if ($review === null || $review->status !== 'reviewed') {
            $blockers[] = 'A cleared manager night-audit review is required.';
        }
        if (DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
            ->where('business_date', $date)->exists()) {
            $blockers[] = 'This business date has already been closed.';
        }

        $nextDate = CarbonImmutable::parse($date)->addDay()->toDateString();
        foreach ([$date => 'Current', $nextDate => 'Next'] as $periodDate => $label) {
            $periods = DB::table('financial_accounting_periods')
                ->where('hotel_id', $hotel->id)->where('status', 'open')
                ->whereDate('starts_on', '<=', $periodDate)
                ->whereDate('ends_on', '>=', $periodDate)->count();
            if ($periods !== 1) {
                $blockers[] = $label.' business date must have exactly one open accounting period.';
            }
        }

        $journals = DB::table('financial_posting_journals')
            ->where('hotel_id', $hotel->id)->where('business_date', $date)
            ->get(['id', 'accounting_period_id']);
        $periodId = DB::table('financial_accounting_periods')
            ->where('hotel_id', $hotel->id)->where('status', 'open')
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->value('id');
        if ($journals->contains(fn ($journal) => (int) $journal->accounting_period_id !== (int) $periodId)) {
            $blockers[] = 'One or more journals reference the wrong accounting period.';
        }

        $invalidJournals = 0;
        foreach ($journals->chunk(500) as $chunk) {
            $totals = [];
            foreach (DB::table('financial_posting_lines')->whereIn('journal_id', $chunk->pluck('id'))
                ->get(['journal_id', 'debit_minor', 'credit_minor']) as $line) {
                $id = (int) $line->journal_id;
                $totals[$id] ??= [0, 0, 0, false];
                $debit = (int) $line->debit_minor;
                $credit = (int) $line->credit_minor;
                $invalid = $debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)
                    || $debit > PHP_INT_MAX - $totals[$id][0]
                    || $credit > PHP_INT_MAX - $totals[$id][1];
                if ($invalid) {
                    $totals[$id][3] = true;
                    continue;
                }
                $totals[$id][0] += $debit;
                $totals[$id][1] += $credit;
                $totals[$id][2]++;
            }
            foreach ($chunk as $journal) {
                $total = $totals[(int) $journal->id] ?? [0, 0, 0, true];
                if ($total[3] || $total[2] < 2 || $total[0] !== $total[1]) {
                    $invalidJournals++;
                }
            }
        }
        if ($invalidJournals > 0) {
            $blockers[] = $invalidJournals.' journal(s) have invalid or unbalanced lines.';
        }

        return response()->json(['data' => [
            'businessDate' => $date,
            'nextBusinessDate' => $nextDate,
            'status' => $clock->status,
            'version' => (int) $clock->version,
            'journalCount' => $journals->count(),
            'invalidJournalCount' => $invalidJournals,
            'nightAuditReview' => $review === null ? null : [
                'status' => $review->status,
                'reviewedBy' => (string) $review->reviewed_by,
                'reviewedAt' => $review->reviewed_at,
            ],
            'blockers' => $blockers,
            'canClose' => $blockers === [],
            'readinessIsAdvisory' => true,
        ]]);
    }

    public function close(Request $request, Hotel $hotel, FinancialBusinessDayCloseService $service): JsonResponse
    {
        $validated = $request->validate([
            'businessDate' => ['required', 'date_format:Y-m-d'],
            'acknowledged' => ['required', 'accepted'],
        ]);
        $date = $validated['businessDate'];

        // The review is append-only; the financial service independently checks
        // and locks the clock, period, journal totals and previous closes.
        $reviewed = DB::table('night_audit_reviews')
            ->where('hotel_id', $hotel->id)->where('business_date', $date)
            ->where('status', 'reviewed')->exists();
        if (!$reviewed) {
            throw ValidationException::withMessages([
                'businessDate' => 'An unflagged, completed manager night-audit review is required before financial day close.',
            ]);
        }

        $result = $service->close((int) $hotel->id, $date, (int) $request->user()->id);

        return response()->json(['data' => [
            'closedBusinessDate' => $result['closed_business_date'],
            'nextBusinessDate' => $result['next_business_date'],
            'reconciliationSnapshot' => $result['snapshot'],
        ]]);
    }
}

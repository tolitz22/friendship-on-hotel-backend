<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ActiveFolioEntryScope;
use App\Models\Hotel;
use App\Services\Finance\FinancialBusinessDayCloseService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Administrator-only financial day close. The immutable operational night-audit
 * review must be cleared before the financial business clock can advance.
 */
final class FinancialBusinessDayController
{
    public function show(Hotel $hotel, ?Request $request = null): JsonResponse
    {
        $clock = DB::table('financial_business_clocks')
            ->where('hotel_id', $hotel->id)->first();

        if ($clock === null) {
            return response()->json(['code' => 'BUSINESS_DATE_NOT_CONFIGURED', 'message' => 'Financial business clock is not configured.'], 409);
        }

        $date = CarbonImmutable::parse($clock->business_date)->toDateString();
        $review = DB::table('night_audit_reviews')
            ->where('hotel_id', $hotel->id)->where('business_date', $date)
            ->first(['status', 'reviewed_by', 'reviewed_at']);

        $openShiftIds = DB::table('cashier_shifts')->where('hotel_id', $hotel->id)
            ->where('status', 'open')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id);

        $blockers = [];
        if ($openShiftIds->isNotEmpty()) {
            $blockers[] = 'Submit cash counts for all open cashier shifts before closing this date.';
        }
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

        $noShowPending = \App\Domain\Reservations\NoShowClosingGuard::unresolved((int) $hotel->id, $date);
        if ($noShowPending > 0 && \App\Domain\Reservations\NoShowWorkflow::enabled((int) $hotel->id)) {
            $blockers[] = $noShowPending.' priced no-show booking(s) require financial reconciliation.';
        }

        if (Schema::hasTable('financial_folio_night_assessments')
                && (config('financial_accounting.enable_live_folio_tax', false)
                    || DB::table('financial_folio_night_assessments')
                        ->where('hotel_id', $hotel->id)
                        ->where('stay_date', $date)->exists())) {
            $pending = DB::table('financial_folio_night_assessments')
                ->where('hotel_id', $hotel->id)->where('stay_date', $date)
                ->where('status', '!=', 'approved')->count();
            if ($pending > 0) {
                $blockers[] = $pending.' folio-night assessment(s) await approval.';
            }
            $approved = DB::table('financial_folio_night_assessments')
                ->where('hotel_id', $hotel->id)->where('stay_date', $date)
                ->where('status', 'approved')->get(['source_journal_id']);
            foreach ($approved as $item) {
                if ($item->source_journal_id === null
                    || !DB::table('financial_room_revenue_recognitions')
                        ->where('hotel_id', $hotel->id)
                        ->where('source_journal_id', $item->source_journal_id)
                        ->where('stay_date', $date)
                        ->whereNotIn('recognition_journal_id', DB::table('financial_posting_journals')
                            ->where('hotel_id', $hotel->id)->whereNotNull('reverses_journal_id')
                            ->select('reverses_journal_id'))->exists()) {
                    $blockers[] = 'An approved room night has not yet been recognized as earned.';
                    break;
                }
            }
            if (Schema::hasTable('folio_entries') && Schema::hasTable('reservation_folios')
                && Schema::hasTable('reservation_records')) {
                $unassessed = ActiveFolioEntryScope::apply(DB::table('folio_entries as e'), 'e')
                    ->join('reservation_folios as f', 'f.id', '=', 'e.folio_id')
                    ->join('reservation_records as r', 'r.id', '=', 'f.reservation_record_id')
                    ->leftJoin('financial_folio_night_assessments as a', function ($join): void {
                        $join->on('a.folio_entry_id', '=', 'e.id')
                            ->on('a.hotel_id', '=', 'e.hotel_id');
                    })
                    ->where('e.hotel_id', $hotel->id)->where('f.hotel_id', $hotel->id)
                    ->where('r.hotel_id', $hotel->id)->where('e.entry_type', 'room_night')
                    ->where('e.stay_date', $date)->whereNotIn('r.lifecycle_state', ['cancelled', 'no_show'])
                    ->whereNull('a.id')->exists();
                if ($unassessed) {
                    $blockers[] = 'Priced room nights for this date still require approved tax allocation.';
                }
            }
        }

        if (Schema::hasTable('financial_folio_night_corrections')
            && Schema::hasTable('financial_folio_night_assessments')) {
            $pendingCorrection = DB::table('financial_folio_night_corrections as c')
                ->join('financial_folio_night_assessments as a', 'a.id', '=', 'c.assessment_id')
                ->where('c.hotel_id', $hotel->id)->where('a.hotel_id', $hotel->id)
                ->where('c.status', 'pending')->exists();
            if ($pendingCorrection) {
                $blockers[] = 'A tax or benefit correction still requires a second review.';
            }
        }

        $noShowIntegrity = null;
        if (config('financial_accounting.enable_no_show_closing_integrity', false)) {
            $noShowIntegrity = app(\App\Services\Finance\NoShowClosingIntegrityService::class)
                ->report($hotel, $date, (int) ($request ?? request())->user()->id);
            if (!$noShowIntegrity['integrityValid']) {
                $blockers[] = \App\Services\Finance\NoShowClosingIntegrityService::blockerMessage($noShowIntegrity);
            }
        }

        return response()->json(['data' => [
            'noShowIntegrity' => $noShowIntegrity,
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
            'unresolvedNoShowCount' => $noShowPending,
            'unresolvedNoShowBookings' => \App\Domain\Reservations\NoShowClosingGuard::bookings((int) $hotel->id, $date),
            'openShiftIds' => $openShiftIds,
            'canClose' => $blockers === [],
            'readinessIsAdvisory' => true,
            'folioTaxAccountingEnabled' => (bool) config('financial_accounting.enable_live_folio_tax', false),
        ]]);
    }

    public function initialize(Request $request, Hotel $hotel): JsonResponse
    {
        $today = CarbonImmutable::now($hotel->timezone)->toDateString();
        $date = $request->validate([
            'businessDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
        ])['businessDate'];

        return DB::transaction(function () use ($hotel, $date, $request): JsonResponse {
            Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            $clocks = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id);
            if ($clocks->exists()) {
                return response()->json(['message' => 'This hotel already has a business date. Refresh readiness to see it.'], 409);
            }
            if (DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->exists()
                || DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->exists()) {
                throw ValidationException::withMessages(['businessDate' => 'Existing financial history requires administrator reconciliation before setup.']);
            }

            $start = CarbonImmutable::parse($date);
            foreach ([$start, $start->addDay()] as $day) {
                $periods = DB::table('financial_accounting_periods')->where('hotel_id', $hotel->id);
                $covering = (clone $periods)->whereDate('starts_on', '<=', $day->toDateString())
                    ->whereDate('ends_on', '>=', $day->toDateString())->get();
                if ($covering->count() === 1 && $covering->first()->status === 'open') {
                    continue;
                }
                $from = $day->startOfMonth()->toDateString();
                $until = $day->endOfMonth()->toDateString();
                if ($periods->whereDate('starts_on', '<=', $until)->whereDate('ends_on', '>=', $from)->exists()) {
                    throw ValidationException::withMessages(['businessDate' => 'Ask the hotel administrator to review the existing accounting periods for '.$day->toDateString().'.']);
                }
                DB::table('financial_accounting_periods')->insert([
                    'hotel_id' => $hotel->id, 'starts_on' => $from, 'ends_on' => $until,
                    'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $clockId = DB::table('financial_business_clocks')->insertGetId([
                'hotel_id' => $hotel->id, 'business_date' => $date, 'status' => 'open',
                'version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('catalog_audit_logs')->insert([
                'hotel_id' => $hotel->id, 'actor_id' => $request->user()->id,
                'subject_type' => 'business_clock', 'subject_id' => $clockId,
                'changes_before' => '{}', 'changes_after' => json_encode(['businessDate' => $date], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return response()->json(['data' => ['businessDate' => $date]], 201);
        }, 3);
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

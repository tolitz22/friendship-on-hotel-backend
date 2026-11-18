<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Serializes day close with all journal postings on the per-hotel business clock.
 * This closes the financial journal day only; operational audit sign-off must
 * be added before this service is exposed to staff.
 */
final class FinancialBusinessDayCloseService
{
    public function close(int $hotelId, string $expectedBusinessDate, int $actorId): array
    {
        if ($hotelId < 1 || $actorId < 1 || !$this->isValidDate($expectedBusinessDate)) {
            throw new InvalidArgumentException('Hotel, actor and ISO business date are required.');
        }

        return DB::transaction(function () use ($hotelId, $expectedBusinessDate, $actorId): array {
            $clock = DB::table('financial_business_clocks')
                ->where('hotel_id', $hotelId)->lockForUpdate()->first();

            if ($clock === null || $clock->status !== 'open'
                || CarbonImmutable::parse($clock->business_date)->toDateString() !== $expectedBusinessDate) {
                throw ValidationException::withMessages(['business_date' => 'Business date changed or is not open.']);
            }

            if (DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                ->where('business_date', $expectedBusinessDate)->exists()) {
                throw ValidationException::withMessages(['business_date' => 'Business date already closed.']);
            }

            $periods = DB::table('financial_accounting_periods')
                ->where('hotel_id', $hotelId)->where('status', 'open')
                ->whereDate('starts_on', '<=', $expectedBusinessDate)
                ->whereDate('ends_on', '>=', $expectedBusinessDate)
                ->lockForUpdate()->get();
            if ($periods->count() !== 1) {
                throw ValidationException::withMessages(['accounting_period' => 'Exactly one open accounting period required.']);
            }
            $period = $periods->first();

            // Do not advance the business clock with unreviewed or unearned
            // accounting for room nights belonging to this financial date.
            if (Schema::hasTable('financial_folio_night_assessments')) {
                $open = DB::table('financial_folio_night_assessments')
                    ->where('hotel_id', $hotelId)->where('stay_date', $expectedBusinessDate)
                    ->where('status', '!=', 'approved')->count();
                if ($open > 0) {
                    throw ValidationException::withMessages([
                        'folio_assessments' => 'Pending or rejected folio-night assessments must be resolved.',
                    ]);
                }
                $approved = DB::table('financial_folio_night_assessments')
                    ->where('hotel_id', $hotelId)->where('stay_date', $expectedBusinessDate)
                    ->where('status', 'approved')->get(['source_journal_id']);
                foreach ($approved as $assessment) {
                    if ($assessment->source_journal_id === null
                        || !DB::table('financial_room_revenue_recognitions')
                            ->where('hotel_id', $hotelId)
                            ->where('source_journal_id', $assessment->source_journal_id)
                            ->where('stay_date', $expectedBusinessDate)
                            ->whereNotIn('recognition_journal_id', DB::table('financial_posting_journals')
                                ->where('hotel_id', $hotelId)->whereNotNull('reverses_journal_id')
                                ->select('reverses_journal_id'))->exists()) {
                        throw ValidationException::withMessages([
                            'revenue_recognition' => 'Every approved room-night assessment must have an earned-revenue journal before day close.',
                        ]);
                    }
                }
                if (Schema::hasTable('folio_entries') && Schema::hasTable('reservation_folios')
                    && Schema::hasTable('reservation_records')) {
                    $unassessed = DB::table('folio_entries as e')
                        ->join('reservation_folios as f', 'f.id', '=', 'e.folio_id')
                        ->join('reservation_records as r', 'r.id', '=', 'f.reservation_record_id')
                        ->leftJoin('financial_folio_night_assessments as a', function ($join) {
                            $join->on('a.folio_entry_id', '=', 'e.id')
                                ->on('a.hotel_id', '=', 'e.hotel_id');
                        })
                        ->where('e.hotel_id', $hotelId)->where('f.hotel_id', $hotelId)
                        ->where('r.hotel_id', $hotelId)->where('e.entry_type', 'room_night')
                        ->where('e.stay_date', $expectedBusinessDate)
                        ->where('r.lifecycle_state', '!=', 'cancelled')
                        ->whereNull('a.id')->exists();
                    if ($unassessed) {
                        throw ValidationException::withMessages([
                            'folio_assessments' => 'All priced room nights must receive reviewed tax allocation before financial close.',
                        ]);
                    }
                }
            }

            if (Schema::hasTable('financial_folio_night_corrections')
                && Schema::hasTable('financial_folio_night_assessments')) {
                $pendingCorrections = DB::table('financial_folio_night_corrections as c')
                    ->join('financial_folio_night_assessments as a', 'a.id', '=', 'c.assessment_id')
                    ->where('c.hotel_id', $hotelId)->where('a.hotel_id', $hotelId)
                    ->where('a.stay_date', $expectedBusinessDate)->where('c.status', 'pending')
                    ->exists();
                if ($pendingCorrections) {
                    throw ValidationException::withMessages([
                        'folio_corrections' => 'Pending tax/benefit corrections must be reviewed before financial day close.',
                    ]);
                }
            }

            // Verify every journal is balanced before the date becomes immutable.
            $journals = DB::table('financial_posting_journals')
                ->where('hotel_id', $hotelId)->where('business_date', $expectedBusinessDate)
                ->get(['id', 'accounting_period_id']);
            $journalIds = $journals->pluck('id')->all();
            if ($journals->contains(fn ($journal) => (int) $journal->accounting_period_id !== (int) $period->id)) {
                throw ValidationException::withMessages(['journals' => 'Journal accounting period mismatch.']);
            }

            $debits = 0;
            $credits = 0;
            $lineCount = 0;
            foreach (array_chunk($journalIds, 500) as $chunk) {
                $lines = DB::table('financial_posting_lines')
                    ->whereIn('journal_id', $chunk)->get(['journal_id', 'debit_minor', 'credit_minor']);
                $totals = [];
                foreach ($lines as $line) {
                    $id = (int) $line->journal_id;
                    $debit = (int) $line->debit_minor;
                    $credit = (int) $line->credit_minor;
                    if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)
                        || $debit > PHP_INT_MAX - $debits || $credit > PHP_INT_MAX - $credits) {
                        throw ValidationException::withMessages(['journals' => 'Invalid journal line or overflow.']);
                    }
                    $totals[$id] ??= [0, 0, 0];
                    $totals[$id][0] += $debit;
                    $totals[$id][1] += $credit;
                    $totals[$id][2]++;
                    $debits += $debit;
                    $credits += $credit;
                    $lineCount++;
                }
                foreach ($chunk as $id) {
                    if (!isset($totals[$id]) || $totals[$id][2] < 2 || $totals[$id][0] !== $totals[$id][1]) {
                        throw ValidationException::withMessages(['journals' => 'Unbalanced or incomplete journal.']);
                    }
                }
            }

            $nextDate = CarbonImmutable::parse($expectedBusinessDate)->addDay()->toDateString();
            $nextPeriods = DB::table('financial_accounting_periods')
                ->where('hotel_id', $hotelId)->where('status', 'open')
                ->whereDate('starts_on', '<=', $nextDate)->whereDate('ends_on', '>=', $nextDate)
                ->lockForUpdate()->get();
            if ($nextPeriods->count() !== 1) {
                throw ValidationException::withMessages(['accounting_period' => 'Next business date requires one open period.']);
            }

            $snapshot = [
                'journal_count' => count($journalIds),
                'line_count' => $lineCount,
                'debits_minor' => $debits,
                'credits_minor' => $credits,
                'currency' => 'PHP',
                'scope' => 'financial_journals_only',
            ];
            $now = now();
            DB::table('financial_day_closes')->insert([
                'hotel_id' => $hotelId,
                'business_date' => $expectedBusinessDate,
                'status' => 'completed',
                'closed_by' => $actorId,
                'closed_at' => $now,
                'reconciliation_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->update([
                'business_date' => $nextDate,
                'version' => DB::raw('version + 1'),
                'updated_at' => $now,
            ]);

            return ['closed_business_date' => $expectedBusinessDate, 'next_business_date' => $nextDate, 'snapshot' => $snapshot];
        }, 3);
    }

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

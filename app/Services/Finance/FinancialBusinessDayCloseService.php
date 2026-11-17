<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

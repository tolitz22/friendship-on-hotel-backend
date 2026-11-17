<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * All financial writers must use the same per-hotel clock lock as day close.
 * Amounts are signed-safe integer minor units (centavos), never floats.
 */
final class FinancialJournalPostingService
{
    /**
     * @param array<int,array{account_code:string,debit_minor:int,credit_minor:int,description?:string}> $lines
     * @return int Persisted journal identifier; repeated idempotent requests return the same ID.
     */
    public function post(
        int $hotelId,
        string $idempotencyKey,
        string $sourceType,
        string $sourceReference,
        string $description,
        array $lines,
        ?int $actorId = null,
        ?int $reversesJournalId = null,
    ): int {
        if ($hotelId < 1 || $idempotencyKey === '' || strlen($idempotencyKey) > 128
            || $sourceType === '' || strlen($sourceType) > 64
            || $sourceReference === '' || strlen($sourceReference) > 128
            || $description === '' || strlen($description) > 255) {
            throw new InvalidArgumentException('Invalid journal identity or description.');
        }

        $this->assertBalanced($lines);

        return DB::transaction(function () use (
            $hotelId, $idempotencyKey, $sourceType, $sourceReference,
            $description, $lines, $actorId, $reversesJournalId
        ): int {
            $clock = DB::table('financial_business_clocks')
                ->where('hotel_id', $hotelId)->lockForUpdate()->first();

            if ($clock === null || $clock->status !== 'open') {
                throw ValidationException::withMessages(['business_date' => 'Financial business date is not open.']);
            }

            // Idempotency must be evaluated while holding the same per-hotel lock.
            $existing = DB::table('financial_posting_journals')
                ->where('hotel_id', $hotelId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->source_type !== $sourceType || $existing->source_reference !== $sourceReference
                    || $existing->description !== $description || (int) ($existing->reverses_journal_id ?? 0) !== ($reversesJournalId ?? 0)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Key already used for a different posting.']);
                }
                $stored = DB::table('financial_posting_lines')->where('journal_id', $existing->id)
                    ->orderBy('line_number')->get(['account_code', 'debit_minor', 'credit_minor', 'description']);
                if ($stored->count() !== count($lines)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Posting payload differs from original.']);
                }
                foreach ($stored as $index => $line) {
                    $requested = $lines[$index];
                    if ($line->account_code !== $requested['account_code']
                        || (int) $line->debit_minor !== $requested['debit_minor']
                        || (int) $line->credit_minor !== $requested['credit_minor']
                        || ($line->description ?? null) !== ($requested['description'] ?? null)) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Posting payload differs from original.']);
                    }
                }
                return (int) $existing->id;
            }

            $date = CarbonImmutable::parse($clock->business_date)->toDateString();
            $period = DB::table('financial_accounting_periods')
                ->where('hotel_id', $hotelId)->where('status', 'open')
                ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
                ->lockForUpdate()->get();

            if ($period->count() !== 1) {
                throw ValidationException::withMessages(['accounting_period' => 'Exactly one open accounting period is required.']);
            }

            if ($reversesJournalId !== null) {
                $original = DB::table('financial_posting_journals')
                    ->where('id', $reversesJournalId)->where('hotel_id', $hotelId)->first();
                if ($original === null) {
                    throw ValidationException::withMessages(['reverses_journal_id' => 'Original journal not found for this hotel.']);
                }
                if (DB::table('financial_posting_journals')->where('reverses_journal_id', $reversesJournalId)->exists()) {
                    throw ValidationException::withMessages(['reverses_journal_id' => 'Journal already reversed.']);
                }
                $originalLines = DB::table('financial_posting_lines')->where('journal_id', $reversesJournalId)
                    ->orderBy('line_number')->get();
                if ($originalLines->count() !== count($lines)) {
                    throw ValidationException::withMessages(['lines' => 'Reversal must exactly negate original lines.']);
                }
                foreach ($originalLines as $index => $originalLine) {
                    $line = $lines[$index];
                    if ($line['account_code'] !== $originalLine->account_code
                        || $line['debit_minor'] !== (int) $originalLine->credit_minor
                        || $line['credit_minor'] !== (int) $originalLine->debit_minor) {
                        throw ValidationException::withMessages(['lines' => 'Reversal must exactly negate original lines.']);
                    }
                }
            }

            $now = now();
            $journalId = DB::table('financial_posting_journals')->insertGetId([
                'hotel_id' => $hotelId,
                'accounting_period_id' => $period->first()->id,
                'business_date' => $date,
                'source_type' => $sourceType,
                'source_reference' => $sourceReference,
                'idempotency_key' => $idempotencyKey,
                'reverses_journal_id' => $reversesJournalId,
                'currency' => 'PHP',
                'description' => $description,
                'posted_by' => $actorId,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lines as $index => $line) {
                DB::table('financial_posting_lines')->insert([
                    'journal_id' => $journalId,
                    'line_number' => $index + 1,
                    'account_code' => $line['account_code'],
                    'debit_minor' => $line['debit_minor'],
                    'credit_minor' => $line['credit_minor'],
                    'description' => $line['description'] ?? null,
                ]);
            }

            return (int) $journalId;
        }, 3);
    }

    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2 || count($lines) > 65535 || array_keys($lines) !== range(0, count($lines) - 1)) {
            throw new InvalidArgumentException('Journal requires two or more sequential lines.');
        }
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            if (!is_array($line) || !isset($line['account_code'], $line['debit_minor'], $line['credit_minor'])
                || !is_string($line['account_code']) || $line['account_code'] === ''
                || strlen($line['account_code']) > 64
                || !is_int($line['debit_minor']) || !is_int($line['credit_minor'])
                || ($line['debit_minor'] > 0) === ($line['credit_minor'] > 0)
                || $line['debit_minor'] < 0 || $line['credit_minor'] < 0
                || (isset($line['description']) && (!is_string($line['description']) || strlen($line['description']) > 255))) {
                throw new InvalidArgumentException('Each line must have exactly one positive debit or credit.');
            }
            if ($line['debit_minor'] > PHP_INT_MAX - $debits || $line['credit_minor'] > PHP_INT_MAX - $credits) {
                throw new InvalidArgumentException('Journal amount overflow.');
            }
            $debits += $line['debit_minor'];
            $credits += $line['credit_minor'];
        }
        if ($debits !== $credits || $debits === 0) {
            throw new InvalidArgumentException('Journal debits and credits must balance.');
        }
    }
}

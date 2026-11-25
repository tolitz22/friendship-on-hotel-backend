<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Recognizes already-deferred room revenue; does not write reservation folios,
 * charge a guest, create payments, or assess VAT.
 *
 * Only source journals explicitly posted as folio_deferred are accepted. The
 * existing booking journals that credit ROOM_REV must never be recognized again.
 */
final class NightlyRoomRevenueRecognitionService
{
    public function recognize(
        int $hotelId,
        int $sourceJournalId,
        string $stayDate,
        int $amountMinor,
        ?int $actorId = null
    ): int {
        if ($hotelId < 1 || $sourceJournalId < 1 || $amountMinor < 1
            || $amountMinor > 1000000000000
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $stayDate)
            || !($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $stayDate))
            || $date->format('Y-m-d') !== $stayDate) {
            throw new InvalidArgumentException('Invalid revenue recognition inputs.');
        }

        return DB::transaction(function () use ($hotelId, $sourceJournalId, $stayDate, $amountMinor, $actorId): int {
            // Use the exact same per-hotel lock as journal writers and day close.
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)
                ->lockForUpdate()->first();
            if ($clock === null || $clock->status !== 'open'
                || CarbonImmutable::parse($clock->business_date)->toDateString() !== $stayDate
                || DB::table('financial_day_closes')->where('hotel_id', $hotelId)
                    ->where('business_date', $stayDate)->exists()) {
                throw ValidationException::withMessages([
                    'stay_date' => 'Revenue can only be recognized for the currently open financial business date.',
                ]);
            }

            $source = DB::table('financial_posting_journals')
                ->where('hotel_id', $hotelId)->where('id', $sourceJournalId)->first();
            if ($source === null || $source->source_type !== 'folio_deferred'
                || $source->reverses_journal_id !== null || $source->currency !== 'PHP') {
                throw ValidationException::withMessages([
                    'source_journal_id' => 'Recognition requires an existing PHP deferred-folio journal.',
                ]);
            }

            // Never recognize deferred revenue after its source has been reversed.
            if (DB::table('financial_posting_journals')
                ->where('hotel_id', $hotelId)->where('reverses_journal_id', $sourceJournalId)->exists()) {
                throw ValidationException::withMessages([
                    'source_journal_id' => 'Deferred-revenue source has already been reversed.',
                ]);
            }

            $linked = DB::table('financial_folio_night_assessments')
                ->where('hotel_id', $hotelId)->where('source_journal_id', $sourceJournalId)->first();
            if ($linked !== null && Schema::hasTable('reservation_pricing_revisions')
                && DB::table('reservation_pricing_revision_nights as n')
                    ->join('reservation_pricing_revisions as r', 'r.id', '=', 'n.revision_id')
                    ->where('r.hotel_id', $hotelId)->where('r.folio_id', $linked->folio_id)
                    ->where('n.kind', 'reversal')->where('n.original_entry_id', $linked->folio_entry_id)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'source_journal_id' => 'Superseded room nights cannot recognize new revenue.',
                ]);
            }

            $approvedNet = $linked === null ? null
                : (new FolioNightCorrectionService())->current($hotelId, $linked)['room_revenue_minor'];
            if ($linked !== null && ($linked->status !== 'approved'
                || $linked->stay_date !== $stayDate
                || $approvedNet !== $amountMinor)) {
                throw ValidationException::withMessages([
                    'amount_minor' => 'Linked folio-night revenue must match its approved date and net revenue amount.',
                ]);
            }

            if ($linked !== null
                && DB::table('financial_folio_night_corrections')
                    ->where('hotel_id', $hotelId)->where('assessment_id', $linked->id)
                    ->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages([
                    'correction' => 'A pending independent correction review blocks new revenue recognition.',
                ]);
            }

            $existing = DB::table('financial_room_revenue_recognitions')
                ->where('hotel_id', $hotelId)->where('source_journal_id', $sourceJournalId)
                ->where('stay_date', $stayDate)->first();
            if ($existing !== null) {
                if ((int) $existing->amount_minor !== $amountMinor) {
                    throw ValidationException::withMessages([
                        'amount_minor' => 'The stay date already has a different recognized amount.',
                    ]);
                }
                if (DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                    ->where('reverses_journal_id', $existing->recognition_journal_id)->exists()) {
                    throw ValidationException::withMessages([
                        'recognition_journal_id' => 'This recognition was reversed and requires a reviewed correction.',
                    ]);
                }
                return (int) $existing->recognition_journal_id;
            }

            $deferred = 0;
            foreach (DB::table('financial_posting_lines')->where('journal_id', $sourceJournalId)
                ->where('account_code', 'DEFERRED_ROOM_REV')
                ->get(['credit_minor', 'debit_minor']) as $line) {
                if ((int) $line->debit_minor !== 0 || (int) $line->credit_minor <= 0
                    || (int) $line->credit_minor > PHP_INT_MAX - $deferred) {
                    throw ValidationException::withMessages(['source_journal_id' => 'Invalid deferred-revenue source lines.']);
                }
                $deferred += (int) $line->credit_minor;
            }
            if ($deferred === 0) {
                throw ValidationException::withMessages([
                    'source_journal_id' => 'The source has no deferred room revenue to recognize.',
                ]);
            }

            $recognized = 0;
            foreach (DB::table('financial_room_revenue_recognitions')
                ->where('hotel_id', $hotelId)->where('source_journal_id', $sourceJournalId)
                ->get(['amount_minor']) as $row) {
                $amount = (int) $row->amount_minor;
                if ($amount < 1 || $amount > $deferred - $recognized) {
                    throw ValidationException::withMessages(['amount_minor' => 'Recorded recognition totals are invalid.']);
                }
                $recognized += $amount;
            }
            if ($amountMinor > $deferred - $recognized) {
                throw ValidationException::withMessages([
                    'amount_minor' => 'Recognition exceeds the remaining deferred room revenue.',
                ]);
            }

            $key = 'room-earned-'.$sourceJournalId.'-'.$stayDate;
            $journalId = (new FinancialJournalPostingService())->post(
                $hotelId, $key, 'nightly_revenue', (string) $sourceJournalId.'@'.$stayDate,
                'Earned room revenue for '.$stayDate,
                [
                    ['account_code' => 'DEFERRED_ROOM_REV', 'debit_minor' => $amountMinor, 'credit_minor' => 0],
                    ['account_code' => 'ROOM_REV', 'debit_minor' => 0, 'credit_minor' => $amountMinor],
                ],
                $actorId,
            );

            DB::table('financial_room_revenue_recognitions')->insert([
                'hotel_id' => $hotelId, 'source_journal_id' => $sourceJournalId,
                'recognition_journal_id' => $journalId, 'stay_date' => $stayDate,
                'amount_minor' => $amountMinor, 'created_at' => now(),
            ]);

            return $journalId;
        }, 3);
    }
    /**
     * Corrections are append-only and never change the folio. The original
     * recognition remains traceable; an adjustment must be explicitly reviewed.
     */
    public function reverse(int $hotelId, int $recognitionJournalId, int $actorId, string $reason): int
    {
        if ($hotelId < 1 || $recognitionJournalId < 1 || $actorId < 1
            || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 180) {
            throw new InvalidArgumentException('A journal, authorized actor and substantive reason are required.');
        }

        return DB::transaction(function () use ($hotelId, $recognitionJournalId, $actorId, $reason): int {
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)
                ->lockForUpdate()->first();
            if ($clock === null || $clock->status !== 'open') {
                throw ValidationException::withMessages(['business_date' => 'Financial business date is not open.']);
            }
            $record = DB::table('financial_room_revenue_recognitions')
                ->where('hotel_id', $hotelId)->where('recognition_journal_id', $recognitionJournalId)->first();
            if ($record === null) {
                throw ValidationException::withMessages(['recognition_journal_id' => 'Recognition journal does not belong to this hotel.']);
            }
            $original = DB::table('financial_posting_journals')
                ->where('hotel_id', $hotelId)->where('id', $recognitionJournalId)->first();
            if ($original === null || $original->source_type !== 'nightly_revenue') {
                throw ValidationException::withMessages(['recognition_journal_id' => 'Recognition journal could not be verified.']);
            }
            $lines = DB::table('financial_posting_lines')->where('journal_id', $recognitionJournalId)
                ->orderBy('line_number')->get(['account_code', 'debit_minor', 'credit_minor'])
                ->map(fn ($line): array => [
                    'account_code' => $line->account_code,
                    'debit_minor' => (int) $line->credit_minor,
                    'credit_minor' => (int) $line->debit_minor,
                ])->all();

            return (new FinancialJournalPostingService())->post(
                $hotelId, 'room-earned-reversal-'.$recognitionJournalId,
                'nightly_revenue_reversal', (string) $recognitionJournalId,
                'Reverse earned revenue: '.trim($reason), $lines,
                $actorId, $recognitionJournalId,
            );
        }, 3);
    }

}

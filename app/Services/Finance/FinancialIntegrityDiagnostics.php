<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

final class FinancialIntegrityDiagnostics
{
    public function report(Hotel $hotel): array
    {
        return DB::transaction(function () use ($hotel): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $id = (int) $hotel->id;
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $id)->lockForUpdate()->first();
            $issues = [];
            $checks = [];
            $date = $clock?->business_date;
            $actor = DB::table('hotel_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.hotel_id', $id)->where('m.role', 'admin')
                ->where('m.is_active', true)->where('u.is_active', true)->orderBy('m.user_id')->value('m.user_id');
            if ($clock === null || $clock->status !== 'open') {
                $issues[] = ['reason' => 'business_clock_not_open'];
            }
            if ($date !== null) {
                if (DB::table('financial_day_closes')->where('hotel_id', $id)->where('business_date', $date)->exists()) {
                    $issues[] = ['reason' => 'business_date_already_closed'];
                }
                if (DB::table('financial_accounting_periods')->where('hotel_id', $id)->where('status', 'open')
                    ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->count() !== 1) {
                    $issues[] = ['reason' => 'open_accounting_period_count_invalid'];
                }
            }
            $checks[] = 'business_clock_and_period';
            $count = 0;
            foreach (DB::table('financial_posting_journals')->where('hotel_id', $id)->lazyById(500) as $journal) {
                $count++;
                $lines = DB::table('financial_posting_lines')->where('journal_id', $journal->id)
                    ->orderBy('line_number')->get(['account_code', 'debit_minor', 'credit_minor']);
                if (!self::balanced($lines)) {
                    $issues[] = ['reason' => 'invalid_or_unbalanced_journal', 'journalId' => (string) $journal->id];
                }
                if ($journal->reverses_journal_id !== null && !DB::table('financial_posting_journals')
                    ->where('hotel_id', $id)->where('id', $journal->reverses_journal_id)->exists()) {
                    $issues[] = ['reason' => 'orphaned_reversal', 'journalId' => (string) $journal->id];
                }
            }
            $checks[] = 'journal_line_balance_and_reversal_scope';
            if ($date !== null && $actor !== null) {
                $closing = app(NoShowClosingIntegrityService::class)->report($hotel, (string) $date, (int) $actor);
                $issues = array_merge($issues, $closing['issues']);
                $checks[] = 'no_show_carry_forward';
            } else {
                $journalReport = app(NoShowJournalIntegrityService::class)->report($hotel);
                $issues = array_merge($issues, $journalReport['issues']);
                $checks[] = 'no_show_journal_sources';
                $issues[] = ['reason' => 'no_show_carry_forward_not_checked'];
            }

            return ['hotelId' => (string) $id, 'businessDate' => $date,
                'readOnly' => true, 'closingCertified' => false, 'releaseCertified' => false,
                'checks' => $checks, 'scannedJournalCount' => $count,
                'issues' => $issues, 'integrityValid' => $issues === [],
                'notChecked' => ['general_folio_reconciliation', 'cashier_reconciliation',
                    'supplier_and_reimbursement_liabilities', 'full_daily_closing_readiness']];
        }, 3);
    }

    public static function balanced(iterable $lines): bool
    {
        $debit = 0;
        $credit = 0;
        $count = 0;
        foreach ($lines as $line) {
            $count++;
            $d = (int) $line->debit_minor;
            $c = (int) $line->credit_minor;
            if ($line->account_code === '' || $d < 0 || $c < 0 || ($d > 0) === ($c > 0)
                || $d > PHP_INT_MAX - $debit || $c > PHP_INT_MAX - $credit) {
                return false;
            }
            $debit += $d;
            $credit += $c;
        }

        return $count >= 2 && $debit > 0 && $debit === $credit;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\NoShowClosingGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NoShowFinancialReport
{
    public function report(int $hotelId, string $date, bool $ledgerVerified): array
    {
        $movement = DB::table('financial_posting_journals as j')
            ->join('financial_posting_lines as l', 'l.journal_id', '=', 'j.id')
            ->where('j.hotel_id', $hotelId)->where('j.business_date', $date)
            ->where(function ($query): void {
                $query->where('j.source_type', 'no_show_fee')->orWhereExists(function ($original): void {
                    $original->selectRaw('1')->from('financial_posting_journals as o')
                        ->whereColumn('o.id', 'j.reverses_journal_id')
                        ->whereColumn('o.hotel_id', 'j.hotel_id')->where('o.source_type', 'no_show_fee');
                });
            })
            ->whereIn('l.account_code', ['NO_SHOW_REV', 'VAT_PAYABLE', 'AR'])
            ->selectRaw('l.account_code, SUM(l.credit_minor) as credits, SUM(l.debit_minor) as debits')
            ->groupBy('l.account_code')->get()->keyBy('account_code');
        $net = static fn (string $code): int => (int) ($movement->get($code)?->credits ?? 0)
            - (int) ($movement->get($code)?->debits ?? 0);
        $cleared = Schema::hasTable('no_show_financial_clearances')
            ? DB::table('no_show_financial_clearances')->where('hotel_id', $hotelId)->where('business_date', $date)->count()
            : 0;
        return [
            'postedRevenueMinor' => $net('NO_SHOW_REV'),
            'postedVatMinor' => $net('VAT_PAYABLE'),
            'postedReceivableMovementMinor' => -$net('AR'),
            'recognizedRevenueMinor' => $ledgerVerified ? $net('NO_SHOW_REV') : null,
            'closedLedgerVerified' => $ledgerVerified,
            'clearancesRecordedCount' => $cleared,
            'currentlyUnresolvedCount' => NoShowClosingGuard::unresolved($hotelId, $date),
            'scope' => 'no_show_fee_journal_movements_not_cash_collections',
        ];
    }
}

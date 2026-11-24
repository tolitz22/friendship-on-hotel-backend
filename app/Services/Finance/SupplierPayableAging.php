<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SupplierPayableAging
{
    public function report(int $hotelId, string $date): array
    {
        $asOf = CarbonImmutable::parse($date)->startOfDay();
        $buckets = array_fill_keys(['current', 'days1To30', 'days31To60', 'days61To90', 'over90'], 0);
        $paid = DB::table('supplier_bill_payments')->where('hotel_id', $hotelId)
            ->where('business_date', '<=', $date)->selectRaw('bill_id, SUM(amount_minor) as paid')->groupBy('bill_id');
        $rows = DB::table('supplier_bills as b')->where('b.hotel_id', $hotelId)
            ->where('b.business_date', '<=', $date)->leftJoinSub($paid, 'p', fn ($join) => $join->on('p.bill_id', '=', 'b.id'))
            ->leftJoin('hotel_suppliers as s', function ($join): void {
                $join->on('s.id', '=', 'b.supplier_id')->on('s.hotel_id', '=', 'b.hotel_id');
            })->select('b.id', 'b.supplier_id', 'b.supplier_name_snapshot', 'b.due_date', 'b.amount_minor', 's.name as current_name')
            ->selectRaw('COALESCE(p.paid, 0) as paid_minor')->orderBy('b.id')->cursor();
        $suppliers = [];
        $outstanding = 0;
        $signed = 0;
        $inconsistent = 0;
        $count = 0;
        foreach ($rows as $row) {
            $balance = (int) $row->amount_minor - (int) $row->paid_minor;
            $signed += $balance;
            if ($balance < 0) { $inconsistent++; continue; }
            if ($balance === 0) continue;
            $days = (int) CarbonImmutable::parse($row->due_date)->startOfDay()->diffInDays($asOf, false);
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => 'days1To30', $days <= 60 => 'days31To60',
                $days <= 90 => 'days61To90', default => 'over90',
            };
            $id = (string) $row->supplier_id;
            $suppliers[$id] ??= ['supplierId' => $id, 'supplierName' => $row->current_name ?? $row->supplier_name_snapshot,
                'outstandingMinor' => 0, 'invoiceCount' => 0, 'buckets' => array_fill_keys(array_keys($buckets), 0)];
            $suppliers[$id]['outstandingMinor'] += $balance;
            $suppliers[$id]['invoiceCount']++;
            $suppliers[$id]['buckets'][$bucket] += $balance;
            $buckets[$bucket] += $balance;
            $outstanding += $balance;
            $count++;
        }
        $ledger = DB::table('financial_posting_journals as j')->join('financial_posting_lines as l', 'l.journal_id', '=', 'j.id')
            ->where('j.hotel_id', $hotelId)->where('j.business_date', '<=', $date)
            ->where('l.account_code', 'accounts_payable')
            ->selectRaw('COALESCE(SUM(l.credit_minor), 0) as credits, COALESCE(SUM(l.debit_minor), 0) as debits')->first();
        $ledgerBalance = (int) $ledger->credits - (int) $ledger->debits;
        usort($suppliers, fn ($a, $b) => ($b['outstandingMinor'] <=> $a['outstandingMinor']) ?: strcmp($a['supplierId'], $b['supplierId']));
        return ['asOf' => $date, 'buckets' => $buckets, 'outstandingMinor' => $outstanding,
            'outstandingCount' => $count, 'overdueMinor' => $outstanding - $buckets['current'],
            'inconsistentCount' => $inconsistent, 'signedSubledgerMinor' => $signed,
            'ledgerBalanceMinor' => $ledgerBalance, 'differenceMinor' => $ledgerBalance - $signed,
            'reconciled' => $inconsistent === 0 && $ledgerBalance === $signed,
            'suppliers' => array_values($suppliers)];
    }
}

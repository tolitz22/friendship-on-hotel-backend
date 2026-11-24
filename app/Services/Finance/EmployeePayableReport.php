<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

final class EmployeePayableReport
{
    public function report(int $hotelId, string $date): array
    {
        $payments = DB::table('employee_reimbursement_payments')->where('hotel_id', $hotelId)
            ->where('business_date', '<=', $date)->selectRaw('claim_id, SUM(amount_minor) as paid')->groupBy('claim_id');
        $rows = DB::table('employee_expense_claims as c')->where('c.hotel_id', $hotelId)
            ->join('employee_expense_claim_decisions as d', function ($join): void {
                $join->on('d.claim_id', '=', 'c.id')->on('d.hotel_id', '=', 'c.hotel_id');
            })->where('d.decision', 'approve')->where('d.business_date', '<=', $date)
            ->leftJoinSub($payments, 'p', 'p.claim_id', '=', 'c.id')
            ->select('c.id', 'c.employee_id', 'c.employee_name_snapshot', 'c.amount_minor')
            ->selectRaw('COALESCE(p.paid, 0) as paid')->orderBy('c.id')->cursor();
        $signed = 0;
        $outstanding = 0;
        $inconsistent = 0;
        $employees = [];
        foreach ($rows as $row) {
            $balance = (int) $row->amount_minor - (int) $row->paid;
            $signed += $balance;
            if ($balance < 0) $inconsistent++;
            if ($balance <= 0) continue;
            $outstanding += $balance;
            $key = (string) $row->employee_id;
            $employees[$key] ??= ['employeeId' => $key, 'employeeName' => $row->employee_name_snapshot, 'outstandingMinor' => 0, 'claimCount' => 0];
            $employees[$key]['outstandingMinor'] += $balance;
            $employees[$key]['claimCount']++;
        }
        $ledger = DB::table('financial_posting_journals as j')
            ->join('financial_posting_lines as l', 'l.journal_id', '=', 'j.id')
            ->where('j.hotel_id', $hotelId)->where('j.business_date', '<=', $date)
            ->where('l.account_code', 'employee_payable')
            ->selectRaw('COALESCE(SUM(l.credit_minor),0) as credits, COALESCE(SUM(l.debit_minor),0) as debits')->first();
        $ledgerBalance = (int) $ledger->credits - (int) $ledger->debits;
        return ['asOf' => $date, 'outstandingMinor' => $outstanding, 'signedSubledgerMinor' => $signed,
            'ledgerBalanceMinor' => $ledgerBalance, 'differenceMinor' => $ledgerBalance - $signed,
            'inconsistentCount' => $inconsistent, 'reconciled' => $inconsistent === 0 && $ledgerBalance === $signed,
            'employees' => array_values($employees)];
    }
}

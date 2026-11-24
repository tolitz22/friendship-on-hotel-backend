<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DailyExpenseBreakdown
{
    public function report(int $hotelId, string $date, int $ledgerNet): array
    {
        // Older report fixtures/deployments may not yet have the expense tables.
        if (!Schema::hasTable('hotel_expenses') || !Schema::hasTable('hotel_expense_correction_decisions')
            || !Schema::hasTable('expense_cash_returns')) {
            return ['available' => false, 'departments' => [], 'attributedNetMinor' => null,
                'unattributedNetMinor' => null, 'voucherMovements' => [], 'movementCount' => 0];
        }

        $base = DB::table('financial_posting_journals as j')
            ->join('financial_posting_lines as l', 'l.journal_id', '=', 'j.id')
            ->where('j.hotel_id', $hotelId)->where('j.business_date', $date)
            ->where('l.account_code', 'operating_expense');
        $original = (clone $base)->where('j.source_type', 'cash_expense')
            ->join('hotel_expenses as e', function ($join): void {
                $join->on('e.journal_id', '=', 'j.id')->on('e.hotel_id', '=', 'j.hotel_id');
            });
        $adjustments = (clone $base)->where('j.source_type', 'cash_expense_adjustment')
            ->join('hotel_expense_correction_decisions as d', function ($join): void {
                $join->on('d.journal_id', '=', 'j.id')->on('d.hotel_id', '=', 'j.hotel_id');
            })
            ->join('hotel_expense_corrections as c', function ($join): void {
                $join->on('c.id', '=', 'd.correction_id')->on('c.hotel_id', '=', 'j.hotel_id');
            })
            ->join('hotel_expenses as e', function ($join): void {
                $join->on('e.id', '=', 'c.expense_id')->on('e.hotel_id', '=', 'j.hotel_id');
            })
            ->where('d.decision', 'approve');
        $refunds = (clone $base)->where('j.source_type', 'cash_expense_refund')
            ->join('expense_cash_returns as r', function ($join): void {
                $join->on('r.journal_id', '=', 'j.id')->on('r.hotel_id', '=', 'j.hotel_id');
            })
            ->join('hotel_expenses as e', function ($join): void {
                $join->on('e.id', '=', 'r.expense_id')->on('e.hotel_id', '=', 'j.hotel_id');
            })->whereNull('r.correction_id');

        $departments = [];
        $movements = [];
        $attributed = 0;
        $count = 0;
        $queries = ['expense' => $original, 'adjustment' => $adjustments, 'refund' => $refunds];
        if (Schema::hasTable('supplier_bills')) {
            $queries['supplier_bill'] = (clone $base)->where('j.source_type', 'supplier_bill')
                ->join('supplier_bills as e', function ($join): void {
                    $join->on('e.journal_id', '=', 'j.id')->on('e.hotel_id', '=', 'j.hotel_id');
                });
        }
        foreach ($queries as $kind => $query) {
            // Historical movement attribution uses the immutable original classification.
            // Later classification overlays belong to voucher history, not closed-day totals.
            $rows = $query->selectRaw('j.id as journal_id, e.id as expense_id, e.department, e.description, e.business_date as original_date, SUM(CAST(l.debit_minor AS DECIMAL(20,0))) - SUM(CAST(l.credit_minor AS DECIMAL(20,0))) as net')
                ->groupBy('j.id', 'e.id', 'e.department', 'e.description', 'e.business_date')
                ->orderBy('j.id')->cursor();
            foreach ($rows as $row) {
                $net = (int) $row->net;
                $department = $row->department;
                $departments[$department] ??= ['department' => $department, 'grossMinor' => 0, 'adjustmentMinor' => 0, 'refundMinor' => 0, 'netMinor' => 0];
                $departments[$department][match ($kind) {
                    'expense', 'supplier_bill' => 'grossMinor', 'adjustment' => 'adjustmentMinor', default => 'refundMinor',
                }] += $kind === 'refund' ? -$net : $net;
                $departments[$department]['netMinor'] += $net;
                $attributed += $net;
                $count++;
                if (count($movements) < 100) {
                    $movements[] = ['journalId' => (string) $row->journal_id,
                        'expenseId' => (string) $row->expense_id, 'kind' => $kind,
                        'department' => $department, 'description' => $row->description,
                        'originalBusinessDate' => (string) $row->original_date, 'netMinor' => $net];
                }
            }
        }
        ksort($departments);
        return ['available' => true, 'departments' => array_values($departments),
            'attributedNetMinor' => $attributed, 'unattributedNetMinor' => $ledgerNet - $attributed,
            'voucherMovements' => $movements, 'movementCount' => $count,
            'scope' => 'original_voucher_department_by_journal_business_date'];
    }
}

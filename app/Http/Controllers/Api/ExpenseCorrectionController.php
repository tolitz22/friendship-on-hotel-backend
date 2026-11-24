<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\ExpenseCorrectionService;
use App\Services\Finance\ExpenseCashReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ExpenseCorrectionController
{
    private function enabled(): void
    {
        abort_unless(config('financial_accounting.enable_expense_corrections', false), 409,
            'Expense corrections and cash returns are not enabled for this deployment.');
    }

    public function requestCorrection(Request $request, Hotel $hotel, string $expense, ExpenseCorrectionService $service): JsonResponse
    {
        $this->enabled();
        $result = $service->request($hotel, (int) $request->user()->id, (int) $expense, $request->all());
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function decide(Request $request, Hotel $hotel, string $correction, ExpenseCorrectionService $service): JsonResponse
    {
        $this->enabled();
        return response()->json(['data' => $service->decide($hotel, (int) $request->user()->id, (int) $correction, $request->all())]);
    }

    public function recordReturn(Request $request, Hotel $hotel, string $shift, string $expense, ExpenseCashReturnService $service): JsonResponse
    {
        $this->enabled();
        $result = $service->record($hotel, (int) $request->user()->id, (int) $expense, (int) $shift, $request->all());
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function reviewReturn(Request $request, Hotel $hotel, string $cashReturn, ExpenseCashReturnService $service): JsonResponse
    {
        $this->enabled();
        return response()->json(['data' => $service->review($hotel, (int) $request->user()->id, (int) $cashReturn, $request->all())]);
    }

    public function history(Hotel $hotel, string $expense): JsonResponse
    {
        $original = DB::table('hotel_expenses')->where('hotel_id', $hotel->id)->where('id', (int) $expense)->first();
        abort_unless($original !== null, 404);
        $corrections = DB::table('hotel_expense_corrections as c')
            ->leftJoin('hotel_expense_correction_decisions as d', function ($join): void {
                $join->on('d.correction_id', '=', 'c.id')->on('d.hotel_id', '=', 'c.hotel_id');
            })
            ->leftJoin('users as requester', 'requester.id', '=', 'c.requested_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'd.reviewed_by')
            ->leftJoin('financial_posting_journals as j', function ($join): void {
                $join->on('j.id', '=', 'd.journal_id')->on('j.hotel_id', '=', 'c.hotel_id');
            })
            ->where('c.hotel_id', $hotel->id)->where('c.expense_id', $original->id)
            ->orderBy('c.id')->get(['c.*', 'requester.name as requester_name', 'reviewer.name as reviewer_name',
                'd.decision', 'd.note', 'd.reviewed_by', 'd.created_at as decided_at', 'd.journal_id',
                'j.business_date as adjustment_date']);
        $returns = DB::table('expense_cash_returns as r')
            ->leftJoin('expense_cash_return_reviews as v', function ($join): void {
                $join->on('v.cash_return_id', '=', 'r.id')->on('v.hotel_id', '=', 'r.hotel_id');
            })
            ->leftJoin('users as recorder', 'recorder.id', '=', 'r.recorded_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'v.reviewed_by')
            ->where('r.hotel_id', $hotel->id)->where('r.expense_id', $original->id)
            ->orderBy('r.id')->get(['r.*', 'recorder.name as recorder_name', 'reviewer.name as reviewer_name',
                'v.decision', 'v.note', 'v.reviewed_by', 'v.created_at as reviewed_at']);
        $effectiveAmount = (int) $original->amount_minor;
        $department = $original->department;
        $category = $original->category;
        $clearing = 0;
        foreach ($corrections as $c) {
            if ($c->decision !== 'approve') continue;
            if ($c->kind === 'amount_correction') {
                $effectiveAmount = (int) $c->corrected_amount_minor;
                $clearing = (int) $original->amount_minor - $effectiveAmount;
            } else {
                $department = $c->corrected_department;
                $category = $c->corrected_category;
            }
        }
        foreach ($returns as $r) {
            if ($r->correction_id === null) $effectiveAmount -= (int) $r->amount_minor;
            else $clearing -= (int) $r->amount_minor;
        }
        return response()->json(['data' => [
            'hotelId' => (string) $hotel->id, 'expenseId' => (string) $original->id,
            'correctionsEnabled' => (bool) config('financial_accounting.enable_expense_corrections', false),
            'originalAmountMinor' => (int) $original->amount_minor,
            'netRecordedExpenseMinor' => $effectiveAmount,
            'effectiveDepartment' => $department, 'effectiveCategory' => $category,
            'clearingBalanceMinor' => $clearing,
            'cashReturnedMinor' => (int) $returns->sum('amount_minor'),
            'unapprovedCashReturnCount' => $returns->filter(fn ($r) => $r->decision !== 'approve')->count(),
            'corrections' => $corrections->map(fn ($c) => [
                'id' => (string) $c->id, 'kind' => $c->kind, 'reason' => $c->reason,
                'requestedBy' => (string) $c->requested_by, 'requestedByName' => $c->requester_name,
                'createdAt' => $c->created_at,
                'correctedAmountMinor' => $c->corrected_amount_minor === null ? null : (int) $c->corrected_amount_minor,
                'correctedDepartment' => $c->corrected_department, 'correctedCategory' => $c->corrected_category,
                'decision' => $c->decision, 'note' => $c->note,
                'reviewedBy' => $c->reviewed_by === null ? null : (string) $c->reviewed_by,
                'reviewedByName' => $c->reviewer_name, 'decidedAt' => $c->decided_at,
                'journalId' => $c->journal_id === null ? null : (string) $c->journal_id,
                'adjustmentBusinessDate' => $c->adjustment_date,
            ])->values(),
            'cashReturns' => $returns->map(fn ($r) => [
                'id' => (string) $r->id, 'shiftId' => (string) $r->shift_id,
                'journalId' => (string) $r->journal_id, 'amountMinor' => (int) $r->amount_minor,
                'correctionId' => $r->correction_id === null ? null : (string) $r->correction_id,
                'reason' => $r->reason, 'evidenceReference' => $r->evidence_reference,
                'recordedBy' => (string) $r->recorded_by, 'recordedByName' => $r->recorder_name,
                'businessDate' => $r->business_date, 'createdAt' => $r->created_at,
                'decision' => $r->decision, 'note' => $r->note,
                'reviewedBy' => $r->reviewed_by === null ? null : (string) $r->reviewed_by,
                'reviewedByName' => $r->reviewer_name, 'reviewedAt' => $r->reviewed_at,
            ])->values(),
        ]]);
    }
}

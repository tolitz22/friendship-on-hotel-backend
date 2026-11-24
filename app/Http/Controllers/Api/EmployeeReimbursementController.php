<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Middleware\EnsureHotelPermission;
use App\Models\Hotel;
use App\Services\Finance\EmployeeExpenseClaimService;
use App\Services\Finance\EmployeeExpenseClaimDecisionService;
use App\Services\Finance\EmployeeReimbursementPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class EmployeeReimbursementController
{
    private function query(Request $request, Hotel $hotel)
    {
        $membership = $request->user()->memberships()->where('hotel_id', $hotel->id)->where('is_active', true)->first();
        abort_unless($membership !== null, 404);
        $all = in_array('reimbursement.read_all', EnsureHotelPermission::effectiveForMembership($membership), true);
        $paid = DB::table('employee_reimbursement_payments')->where('hotel_id', $hotel->id)
            ->selectRaw('claim_id, SUM(amount_minor) as paid')->groupBy('claim_id');
        $q = DB::table('employee_expense_claims as c')->where('c.hotel_id', $hotel->id)
            ->leftJoin('employee_expense_claim_decisions as d', function ($join): void {
                $join->on('d.claim_id', '=', 'c.id')->on('d.hotel_id', '=', 'c.hotel_id');
            })->leftJoinSub($paid, 'p', 'p.claim_id', '=', 'c.id')
            ->select('c.*', 'd.decision', 'd.note as review_note', 'd.reviewed_by', 'd.business_date as approval_date', 'd.journal_id')
            ->selectRaw('COALESCE(p.paid, 0) as paid_minor');
        if (!$all) $q->where('c.employee_id', $request->user()->id);
        return $q;
    }

    private function present(object $c): array
    {
        $paid = (int) $c->paid_minor;
        $amount = (int) $c->amount_minor;
        $approved = $c->decision === 'approve';
        $remaining = $approved ? $amount - $paid : 0;
        $status = (!$approved && $paid !== 0) || $remaining < 0 ? 'inconsistent'
            : (!$approved ? ($c->decision === 'reject' ? 'rejected' : 'pending')
                : ($remaining === 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'approved')));
        return ['id' => (string) $c->id, 'employeeId' => (string) $c->employee_id,
            'employeeName' => $c->employee_name_snapshot, 'merchant' => $c->merchant,
            'description' => $c->description, 'department' => $c->department, 'category' => $c->category,
            'receiptReference' => $c->receipt_reference, 'serviceDate' => (string) $c->service_date,
            'currency' => $c->currency, 'amountMinor' => $amount, 'paidMinor' => $paid,
            'outstandingMinor' => $remaining, 'status' => $status, 'reviewNote' => $c->review_note,
            'reviewedBy' => $c->reviewed_by === null ? null : (string) $c->reviewed_by,
            'approvalDate' => $c->approval_date, 'createdAt' => $c->created_at];
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $filters = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $page = $this->query($request, $hotel)->orderByDesc('c.id')->paginate((int) ($filters['per_page'] ?? 20));
        return response()->json(['data' => $page->getCollection()->map(fn ($c) => $this->present($c))->values(),
            'meta' => ['page' => $page->currentPage(), 'totalPages' => $page->lastPage(), 'total' => $page->total()],
            'postingEnabled' => (bool) config('financial_accounting.enable_employee_reimbursements', false)]);
    }

    public function show(Request $request, Hotel $hotel, int $claim): JsonResponse
    {
        $row = $this->query($request, $hotel)->where('c.id', $claim)->first();
        abort_unless($row !== null, 404);
        $payments = DB::table('employee_reimbursement_payments')->where('hotel_id', $hotel->id)->where('claim_id', $claim)->orderBy('id')->get();
        $row->paid_minor = $payments->sum('amount_minor');
        return response()->json(['data' => $this->present($row) + ['payments' => $payments->map(fn ($p) => [
            'id' => (string) $p->id, 'amountMinor' => (int) $p->amount_minor, 'paymentSource' => $p->payment_source,
            'evidenceReference' => $p->evidence_reference, 'businessDate' => $p->business_date,
            'shiftId' => $p->shift_id === null ? null : (string) $p->shift_id,
        ])->all()]]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data = app(EmployeeExpenseClaimService::class)->submit($hotel, (int) $request->user()->id, $request->all());
        return response()->json(['data' => $data], $data['replayed'] ? 200 : 201);
    }

    public function decide(Request $request, Hotel $hotel, int $claim): JsonResponse
    {
        $data = app(EmployeeExpenseClaimDecisionService::class)->decide($hotel, (int) $request->user()->id, $claim, $request->all());
        return response()->json(['data' => $data], $data['replayed'] ? 200 : 201);
    }

    public function pay(Request $request, Hotel $hotel, int $claim): JsonResponse
    {
        $data = app(EmployeeReimbursementPaymentService::class)->record($hotel, (int) $request->user()->id, $claim, $request->all());
        return response()->json(['data' => $data], $data['replayed'] ? 200 : 201);
    }
}

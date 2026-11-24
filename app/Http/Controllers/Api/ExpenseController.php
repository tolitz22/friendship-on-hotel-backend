<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\CashExpensePostingService;
use App\Services\Finance\ExpensePolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ExpenseController
{
    private function query(Hotel $hotel)
    {
        return DB::table('hotel_expenses as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.recorded_by')
            ->leftJoin('cashier_expense_payouts as p', function ($join): void {
                $join->on('p.expense_id', '=', 'e.id')->on('p.hotel_id', '=', 'e.hotel_id');
            })
            ->where('e.hotel_id', $hotel->id)
            ->select('e.*', 'u.name as recorded_by_name', 'p.shift_id');
    }

    private function present(object $e): array
    {
        return [
            'id' => (string) $e->id, 'journalId' => (string) $e->journal_id,
            'recordedBy' => (string) $e->recorded_by, 'recordedByName' => $e->recorded_by_name,
            'shiftId' => $e->shift_id === null ? null : (string) $e->shift_id,
            'payee' => $e->payee, 'description' => $e->description,
            'department' => $e->department, 'category' => $e->category,
            'serviceDate' => $e->service_date, 'businessDate' => $e->business_date,
            'currency' => $e->currency, 'amountMinor' => (int) $e->amount_minor,
            'paymentSource' => $e->payment_source, 'paymentStatus' => 'paid',
            'reviewStatus' => $e->review_status, 'reviewReason' => $e->review_reason,
            'policyLimitMinor' => $e->policy_limit_minor === null ? null : (int) $e->policy_limit_minor,
            'invoiceReference' => $e->invoice_reference, 'createdAt' => $e->created_at,
        ];
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $request->filled('from') ? ['after_or_equal:from'] : []),
            'department' => ['sometimes', 'in:rooms,maintenance,administration,utilities,food_beverage'],
            'reviewStatus' => ['sometimes', 'in:pending,approved,flagged,not_required'],
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->query($hotel);
        if (isset($filters['from'])) $query->where('e.business_date', '>=', $filters['from']);
        if (isset($filters['to'])) $query->where('e.business_date', '<=', $filters['to']);
        if (isset($filters['department'])) $query->where('e.department', $filters['department']);
        if (isset($filters['reviewStatus'])) $query->where('e.review_status', $filters['reviewStatus']);
        if (isset($filters['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['search'])).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw("LOWER(e.payee) LIKE LOWER(?) ESCAPE '!'", [$term])
                    ->orWhereRaw("LOWER(e.description) LIKE LOWER(?) ESCAPE '!'", [$term])
                    ->orWhereRaw("LOWER(e.invoice_reference) LIKE LOWER(?) ESCAPE '!'", [$term]);
            });
        }
        $page = $query->orderByDesc('e.business_date')->orderByDesc('e.id')
            ->paginate((int) ($filters['per_page'] ?? 20));
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->first();
        $month = $clock === null ? null : CarbonImmutable::parse($clock->business_date);
        $base = DB::table('hotel_expenses')->where('hotel_id', $hotel->id);
        return response()->json([
            'data' => $page->getCollection()->map(fn ($e) => $this->present($e))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage()],
            'summary' => [
                'businessDate' => $clock?->business_date,
                'monthExpenseMinor' => $month === null ? null : (int) (clone $base)
                    ->whereBetween('business_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
                    ->sum('amount_minor'),
                'pendingReviewCount' => (clone $base)->where('review_status', 'pending')->count(),
                'flaggedCount' => (clone $base)->where('review_status', 'flagged')->count(),
                'supplierBalancesSupported' => false,
            ],
            'postingEnabled' => (bool) config('financial_accounting.enable_expense_posting', false),
        ]);
    }

    public function exceptions(Hotel $hotel): JsonResponse
    {
        // Current property-wide work queue, deliberately independent of historical report dates.
        $corrections = DB::table('hotel_expense_corrections as c')
            ->leftJoin('hotel_expense_correction_decisions as d', function ($join): void {
                $join->on('d.correction_id', '=', 'c.id')->on('d.hotel_id', '=', 'c.hotel_id');
            })
            ->join('hotel_expenses as e', function ($join): void {
                $join->on('e.id', '=', 'c.expense_id')->on('e.hotel_id', '=', 'c.hotel_id');
            })->where('c.hotel_id', $hotel->id);
        $pending = (clone $corrections)->whereNull('d.id');
        $returns = DB::table('expense_cash_returns as r')
            ->leftJoin('expense_cash_return_reviews as v', function ($join): void {
                $join->on('v.cash_return_id', '=', 'r.id')->on('v.hotel_id', '=', 'r.hotel_id');
            })
            ->join('hotel_expenses as e', function ($join): void {
                $join->on('e.id', '=', 'r.expense_id')->on('e.hotel_id', '=', 'r.hotel_id');
            })->where('r.hotel_id', $hotel->id);
        $unreviewed = (clone $returns)->whereNull('v.id');
        $flagged = (clone $returns)->where('v.decision', 'flag');
        $items = [];
        foreach ($pending->orderBy('c.id')->limit(50)->get(['c.id', 'c.expense_id', 'e.description', 'e.business_date']) as $row) {
            $items[] = ['kind' => 'pending_correction', 'id' => (string) $row->id, 'expenseId' => (string) $row->expense_id,
                'description' => $row->description, 'businessDate' => (string) $row->business_date, 'amountMinor' => null];
        }
        foreach (['pending_return' => $unreviewed, 'flagged_return' => $flagged] as $kind => $query) {
            foreach ((clone $query)->orderBy('r.id')->limit(50)->get(['r.id', 'r.expense_id', 'r.amount_minor', 'e.description', 'e.business_date']) as $row) {
                $items[] = ['kind' => $kind, 'id' => (string) $row->id, 'expenseId' => (string) $row->expense_id,
                    'description' => $row->description, 'businessDate' => (string) $row->business_date, 'amountMinor' => (int) $row->amount_minor];
            }
        }
        $settlements = DB::table('expense_cash_returns')->where('hotel_id', $hotel->id)
            ->whereNotNull('correction_id')->selectRaw('correction_id, SUM(amount_minor) as settled')->groupBy('correction_id');
        $clearing = (clone $corrections)->where('c.kind', 'amount_correction')->where('d.decision', 'approve')
            ->leftJoinSub($settlements, 's', fn ($join) => $join->on('s.correction_id', '=', 'c.id'))
            ->whereRaw('CAST(e.amount_minor AS DECIMAL(20,0)) - CAST(c.corrected_amount_minor AS DECIMAL(20,0)) - COALESCE(s.settled, 0) <> 0');
        $clearingCount = (clone $clearing)->count();
        foreach ($clearing->orderBy('c.id')->limit(50)->get(['c.id', 'c.expense_id', 'e.description', 'e.business_date',
            DB::raw('CAST(e.amount_minor AS DECIMAL(20,0)) - CAST(c.corrected_amount_minor AS DECIMAL(20,0)) - COALESCE(s.settled, 0) as balance')]) as $row) {
            $items[] = ['kind' => 'unresolved_clearing', 'id' => (string) $row->id, 'expenseId' => (string) $row->expense_id,
                'description' => $row->description, 'businessDate' => (string) $row->business_date, 'amountMinor' => (int) $row->balance];
        }
        return response()->json(['data' => [
            'hotelId' => (string) $hotel->id,
            'pendingCorrectionCount' => $pending->count(),
            'pendingReturnCount' => $unreviewed->count(),
            'flaggedReturnCount' => $flagged->count(),
            'unresolvedClearingCount' => $clearingCount,
            'items' => $items,
        ]]);
    }

    public function show(Hotel $hotel, string $expense): JsonResponse
    {
        $row = $this->query($hotel)->where('e.id', (int) $expense)->first();
        abort_unless($row !== null, 404);
        $review = DB::table('hotel_expense_reviews as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.reviewed_by')
            ->where('r.hotel_id', $hotel->id)->where('r.expense_id', $row->id)
            ->first(['r.reviewed_by', 'u.name as reviewer_name', 'r.decision', 'r.note', 'r.created_at']);
        return response()->json(['data' => $this->present($row) + [
            'review' => $review === null ? null : [
                'reviewedBy' => (string) $review->reviewed_by, 'reviewedByName' => $review->reviewer_name,
                'decision' => $review->decision, 'note' => $review->note, 'createdAt' => $review->created_at,
            ],
        ]]);
    }

    public function record(Request $request, Hotel $hotel, string $shift, CashExpensePostingService $service): JsonResponse
    {
        abort_unless(config('financial_accounting.enable_expense_posting', false), 409,
            'Expense posting is not enabled for this deployment.');
        $result = $service->record($hotel, (int) $request->user()->id, (int) $shift, $request->all());
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function policy(Hotel $hotel, ExpensePolicyService $service): JsonResponse
    {
        return response()->json(['data' => $service->policy($hotel)]);
    }

    public function updatePolicy(Request $request, Hotel $hotel, ExpensePolicyService $service): JsonResponse
    {
        return response()->json(['data' => $service->update($hotel, (int) $request->user()->id, $request->all())]);
    }

    public function review(Request $request, Hotel $hotel, string $expense, ExpensePolicyService $service): JsonResponse
    {
        return response()->json(['data' => $service->review($hotel, (int) $request->user()->id, (int) $expense, $request->all())]);
    }
}

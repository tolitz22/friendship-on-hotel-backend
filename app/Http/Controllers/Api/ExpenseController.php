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
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
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

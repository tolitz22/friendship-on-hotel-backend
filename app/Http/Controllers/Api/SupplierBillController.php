<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\SupplierBillPostingService;
use App\Services\Finance\SupplierBillPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupplierBillController
{
    private const BALANCE = 'CAST(b.amount_minor AS DECIMAL(20,0)) - COALESCE(p.paid, 0)';

    private function query(Hotel $hotel)
    {
        $paid = DB::table('supplier_bill_payments')->where('hotel_id', $hotel->id)
            ->selectRaw('bill_id, SUM(amount_minor) as paid')->groupBy('bill_id');
        return DB::table('supplier_bills as b')->where('b.hotel_id', $hotel->id)
            ->leftJoinSub($paid, 'p', fn ($join) => $join->on('p.bill_id', '=', 'b.id'));
    }

    private function date(Hotel $hotel): ?string
    {
        $date = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->value('business_date');
        return $date === null ? null : (string) $date;
    }

    private function present(object $b, ?string $date): array
    {
        $amount = (int) $b->amount_minor;
        $paid = (int) $b->paid_minor;
        $balance = $amount - $paid;
        return [
            'id' => (string) $b->id, 'supplierId' => (string) $b->supplier_id,
            'supplierName' => $b->supplier_name_snapshot, 'invoiceReference' => $b->invoice_reference,
            'description' => $b->description, 'department' => $b->department, 'category' => $b->category,
            'invoiceDate' => $b->invoice_date, 'serviceDate' => $b->service_date,
            'dueDate' => $b->due_date, 'businessDate' => $b->business_date,
            'currency' => $b->currency, 'amountMinor' => $amount, 'paidMinor' => $paid,
            'outstandingMinor' => $balance,
            'paymentStatus' => $balance < 0 ? 'inconsistent' : ($balance === 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid')),
            'overdue' => $date !== null && $balance > 0 && (string) $b->due_date < $date,
            'journalId' => (string) $b->journal_id, 'recordedBy' => (string) $b->recorded_by,
            'createdAt' => $b->created_at,
        ];
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $filters = $request->validate([
            'supplierId' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:all,unpaid,partially_paid,paid,overdue,inconsistent'],
            'search' => ['sometimes', 'string', 'max:160'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $date = $this->date($hotel);
        $base = $this->query($hotel);
        $balance = self::BALANCE;
        $positive = (clone $base)->whereRaw("$balance > 0");
        $overdue = (clone $positive);
        if ($date === null) $overdue->whereRaw('1 = 0');
        else $overdue->where('b.due_date', '<', $date);
        $summary = [
            'businessDate' => $date,
            'outstandingMinor' => (int) (clone $positive)->selectRaw("COALESCE(SUM($balance), 0) as total")->value('total'),
            'outstandingCount' => (clone $positive)->count(),
            'overdueMinor' => $date === null ? null : (int) (clone $overdue)->selectRaw("COALESCE(SUM($balance), 0) as total")->value('total'),
            'overdueCount' => $date === null ? null : (clone $overdue)->count(),
            'inconsistentCount' => (clone $base)->whereRaw("$balance < 0")->count(),
        ];
        $query = clone $base;
        if (isset($filters['supplierId'])) $query->where('b.supplier_id', $filters['supplierId']);
        switch ($filters['status'] ?? 'all') {
            case 'unpaid': $query->whereRaw("$balance > 0 AND COALESCE(p.paid, 0) = 0"); break;
            case 'partially_paid': $query->whereRaw("$balance > 0 AND COALESCE(p.paid, 0) > 0"); break;
            case 'paid': $query->whereRaw("$balance = 0"); break;
            case 'inconsistent': $query->whereRaw("$balance < 0"); break;
            case 'overdue':
                $query->whereRaw("$balance > 0");
                if ($date === null) $query->whereRaw('1 = 0');
                else $query->where('b.due_date', '<', $date);
                break;
        }
        if (isset($filters['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['search'])).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw("LOWER(b.supplier_name_snapshot) LIKE LOWER(?) ESCAPE '!'", [$term])
                    ->orWhereRaw("LOWER(b.invoice_reference) LIKE LOWER(?) ESCAPE '!'", [$term])
                    ->orWhereRaw("LOWER(b.description) LIKE LOWER(?) ESCAPE '!'", [$term]);
            });
        }
        $page = $query->select('b.*')->selectRaw('COALESCE(p.paid, 0) as paid_minor')
            ->orderBy('b.due_date')->orderByDesc('b.id')->paginate((int) ($filters['per_page'] ?? 20));
        return response()->json(['data' => $page->getCollection()->map(fn ($b) => $this->present($b, $date))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage()],
            'summary' => $summary, 'postingEnabled' => (bool) config('financial_accounting.enable_supplier_billing', false)]);
    }

    public function show(Hotel $hotel, string $bill): JsonResponse
    {
        $row = $this->query($hotel)->where('b.id', $bill)->select('b.*')
            ->selectRaw('COALESCE(p.paid, 0) as paid_minor')->first();
        abort_unless($row !== null, 404);
        $payments = DB::table('supplier_bill_payments as p')->leftJoin('users as u', 'u.id', '=', 'p.recorded_by')
            ->where('p.hotel_id', $hotel->id)->where('p.bill_id', $row->id)
            ->orderBy('p.id')->get(['p.*', 'u.name as actor_name'])->map(fn ($p) => [
                'id' => (string) $p->id, 'journalId' => (string) $p->journal_id,
                'amountMinor' => (int) $p->amount_minor, 'paymentSource' => $p->payment_source,
                'shiftId' => $p->shift_id === null ? null : (string) $p->shift_id,
                'evidenceReference' => $p->evidence_reference, 'businessDate' => $p->business_date,
                'recordedBy' => (string) $p->recorded_by, 'recordedByName' => $p->actor_name,
                'createdAt' => $p->created_at,
            ])->all();
        return response()->json(['data' => $this->present($row, $this->date($hotel)) + ['payments' => $payments]]);
    }

    public function store(Request $request, Hotel $hotel, SupplierBillPostingService $service): JsonResponse
    {
        $result = $service->record($hotel, (int) $request->user()->id, $request->all());
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function pay(Request $request, Hotel $hotel, string $bill, SupplierBillPaymentService $service): JsonResponse
    {
        $result = $service->record($hotel, (int) $request->user()->id, (int) $bill, $request->all());
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class FinancialActivityNotifications
{
    public const KINDS = ['payment_recorded', 'payment_pending_review', 'payment_verified', 'payment_rejected', 'payment_voided', 'refund_requested', 'refund_verified', 'expense_recorded', 'expense_correction_requested', 'cashier_discrepancy'];

    private function query(Hotel $hotel, string $kind): ?Builder
    {
        $table = match ($kind) {
            'payment_recorded', 'payment_pending_review', 'payment_verified', 'payment_rejected' => 'folio_payment_records',
            'payment_voided' => 'folio_payment_voids',
            'refund_requested' => 'folio_refund_requests',
            'refund_verified' => 'folio_refund_disbursement_evidence',
            'expense_recorded' => 'hotel_expenses',
            'expense_correction_requested' => 'hotel_expense_corrections',
            'cashier_discrepancy' => 'cashier_shifts',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        $timestamp = match ($kind) {
            'payment_verified', 'payment_rejected' => 'updated_at',
            'refund_verified' => 'reviewed_at',
            'cashier_discrepancy' => 'submitted_at',
            default => 'created_at',
        };
        $query = DB::table($table.' as e')->where('e.hotel_id', $hotel->id)
            ->where('e.'.$timestamp, '>=', now()->subDay())
            ->select(['e.id', 'e.'.$timestamp.' as occurred_at']);
        match ($kind) {
            'payment_pending_review' => $query->where('e.status', 'pending_review'),
            'payment_verified' => $query->where('e.status', 'staff_confirmed')->whereNotNull('e.reviewed_by'),
            'payment_rejected' => $query->where('e.status', 'rejected')->whereNotNull('e.reviewed_by'),
            'refund_verified' => $query->where('e.status', 'verified'),
            'refund_requested' => $query->where('e.status', 'requested'),
            'expense_correction_requested' => $query->whereNotExists(function ($decision): void {
                $decision->selectRaw('1')->from('hotel_expense_correction_decisions as d')
                    ->whereColumn('d.hotel_id', 'e.hotel_id')->whereColumn('d.correction_id', 'e.id');
            }),
            'cashier_discrepancy' => $query->where('e.variance_minor', '!=', 0)->whereIn('e.status', ['submitted', 'flagged']),
            default => null,
        };
        if (str_starts_with($kind, 'payment_') || str_starts_with($kind, 'refund_')) {
            $query->join('reservation_folios as f', function ($join): void {
                $join->on('f.id', '=', 'e.folio_id')->on('f.hotel_id', '=', 'e.hotel_id');
            })->join('reservation_records as b', function ($join): void {
                $join->on('b.id', '=', 'f.reservation_record_id')->on('b.hotel_id', '=', 'e.hotel_id');
            })->addSelect(['b.id as booking_id', 'b.reference']);
            if (str_starts_with($kind, 'payment_')) {
                $query->addSelect($kind === 'payment_voided' ? 'e.payment_record_id as payment_id' : 'e.id as payment_id');
            }
        } elseif ($kind === 'expense_correction_requested') {
            $query->join('hotel_expenses as x', function ($join): void {
                $join->on('x.id', '=', 'e.expense_id')->on('x.hotel_id', '=', 'e.hotel_id');
            })->addSelect('x.id as expense_id');
        } elseif ($kind === 'expense_recorded') {
            $query->addSelect('e.id as expense_id');
        }

        return $query->orderByDesc('e.'.$timestamp)->orderByDesc('e.id');
    }

    private function item(Hotel $hotel, string $kind, object $row): array
    {
        $item = [
            'id' => $kind.':'.$row->id,
            'kind' => $kind,
            'date' => Carbon::parse($row->occurred_at)->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString(),
        ];
        if (isset($row->booking_id)) {
            return $item + ['bookingId' => (string) $row->booking_id, 'reference' => $row->reference,
                'target' => 'billing', 'paymentId' => isset($row->payment_id) ? (string) $row->payment_id : null];
        }
        if (isset($row->expense_id)) {
            return $item + ['reference' => 'Expense #'.$row->expense_id, 'target' => 'expenses', 'expenseId' => (string) $row->expense_id];
        }

        return $item + ['reference' => 'Cashier shift #'.$row->id, 'target' => 'cashier-shifts', 'shiftId' => (string) $row->id];
    }

    public function all(Hotel $hotel, ?int $limit = null): iterable
    {
        foreach (self::KINDS as $kind) {
            $query = $this->query($hotel, $kind);
            $rows = $limit === null ? $query->lazy(200) : $query->limit($limit)->get();
            foreach ($rows as $row) {
                yield $this->item($hotel, $kind, $row);
            }
        }
    }

    public function current(Hotel $hotel, string $id): ?array
    {
        if (! preg_match('/^([a-z_]+):([1-9][0-9]*)$/D', $id, $parts)) {
            return null;
        }
        $row = $this->query($hotel, $parts[1])?->where('e.id', $parts[2])->first();

        return $row ? $this->item($hotel, $parts[1], $row) : null;
    }
}

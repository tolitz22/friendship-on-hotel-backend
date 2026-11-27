<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PlatformSubscriptionPayments
{
    public static function invoices(): Builder
    {
        $totals = DB::table('platform_subscription_payments')->select('invoice_id')
            ->selectRaw("SUM(CASE WHEN status = 'verified' THEN amount_minor ELSE 0 END) AS verified_minor")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN amount_minor ELSE 0 END) AS pending_minor")
            ->groupBy('invoice_id');

        return DB::table('platform_subscription_invoices as invoice')
            ->leftJoinSub($totals, 'payment_totals', 'invoice.id', '=', 'payment_totals.invoice_id')
            ->select('invoice.*')
            ->selectRaw('COALESCE(payment_totals.verified_minor, 0) AS verified_minor')
            ->selectRaw('COALESCE(payment_totals.pending_minor, 0) AS pending_minor');
    }

    public static function paid(int $invoiceId): int
    {
        return (int) DB::table('platform_subscription_payments')->where('invoice_id', $invoiceId)->where('status', 'verified')->sum('amount_minor');
    }

    public static function pending(int $invoiceId): int
    {
        return (int) DB::table('platform_subscription_payments')->where('invoice_id', $invoiceId)->where('status', 'pending')->sum('amount_minor');
    }

    public static function project(object $row): array
    {
        return [
            'id' => (string) $row->id, 'method' => $row->method, 'reference' => $row->reference,
            'evidenceReference' => $row->evidence_reference, 'amountMinor' => (int) $row->amount_minor,
            'receivedOn' => substr((string) $row->received_on, 0, 10), 'status' => $row->status,
            'recordedBy' => $row->recorded_by === null ? null : (string) $row->recorded_by,
            'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
            'reviewNote' => $row->review_note, 'reviewedAt' => $row->reviewed_at,
            'selfReviewed' => (bool) $row->self_reviewed, 'createdAt' => $row->created_at,
        ];
    }
}

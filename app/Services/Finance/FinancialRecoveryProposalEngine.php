<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FinancialRecoveryProposalEngine
{
    public function proposals(Hotel $hotel, array $report): array
    {
        $proposals = [];
        foreach ($report['issues'] as $finding) {
            $details = $finding['reason'] === 'ledger_not_reconciled' ? ($finding['details'] ?? []) : [];
            if ($details === []) {
                $details = [$finding];
            }
            foreach ($details as $detail) {
                $reason = $detail['reason'] ?? $finding['reason'];
                $context = [];
                $paymentId = NoShowJournalIntegrityService::sourceId('folio-payment-', (string) ($detail['reference'] ?? $detail['sourceReference'] ?? ''));
                if ($paymentId !== null) {
                    $context = $this->paymentContext($hotel, $paymentId, $finding['bookingId'] ?? null);
                }
                $journalIds = $detail['journalIds'] ?? $finding['journalIds'] ?? [];
                if (isset($detail['journalId'])) {
                    $journalIds[] = $detail['journalId'];
                }
                $journalIds = array_values(array_unique(array_map('strval', $journalIds)));
                sort($journalIds, SORT_STRING);
                if ($journalIds !== []) {
                    $context['journals'] = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                        ->whereIn('id', $journalIds)->orderBy('id')->get(['id', 'business_date', 'source_type', 'source_reference'])
                        ->map(fn ($row) => ['id' => (string) $row->id, 'businessDate' => (string) $row->business_date,
                            'sourceType' => $row->source_type, 'sourceReference' => $row->source_reference,
                            'closedDate' => DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                                ->where('business_date', $row->business_date)->exists()])->all();
                }
                $proposal = [
                    'hotelId' => (string) $hotel->id, 'businessDate' => $report['businessDate'],
                    'finding' => $finding, 'detail' => $detail, 'reason' => $reason,
                    'severity' => $reason === 'no_show_carry_forward_not_checked' ? 'high' : 'critical',
                    'context' => $context, 'action' => self::action($reason),
                    'approvalRequired' => true, 'executable' => false,
                    'eligibility' => 'investigation_only', 'closedPeriodsImmutable' => true,
                ];
                $proposal['fingerprint'] = self::fingerprint($proposal);
                $proposals[$proposal['fingerprint']] = $proposal;
            }
        }
        ksort($proposals);

        return array_values($proposals);
    }

    private function paymentContext(Hotel $hotel, string $paymentId, mixed $bookingId): array
    {
        $payment = DB::table('folio_payment_records as p')
            ->join('reservation_folios as f', function ($join): void {
                $join->on('f.id', '=', 'p.folio_id')->on('f.hotel_id', '=', 'p.hotel_id');
            })
            ->join('reservation_records as r', function ($join): void {
                $join->on('r.id', '=', 'f.reservation_record_id')->on('r.hotel_id', '=', 'f.hotel_id');
            })
            ->where('p.hotel_id', $hotel->id)->where('p.id', $paymentId)
            ->when($bookingId !== null, fn ($query) => $query->where('r.id', $bookingId))
            ->first(['p.id', 'p.status', 'p.method', 'p.amount_minor', 'p.created_at', 'p.folio_id',
                'r.id as booking_id', 'r.lifecycle_state']);
        if ($payment === null) {
            return ['paymentId' => $paymentId, 'sourceFound' => false];
        }
        $date = CarbonImmutable::parse((string) $payment->created_at, config('app.timezone', 'UTC'))
            ->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $activity = [];
        foreach (['folio_settlement_verifications', 'folio_settlement_allocations', 'folio_refund_requests'] as $table) {
            $activity[$table] = DB::table($table)->where('hotel_id', $hotel->id)
                ->where('payment_record_id', $paymentId)->count();
        }
        return ['paymentId' => $paymentId, 'sourceFound' => true, 'bookingId' => (string) $payment->booking_id,
            'folioId' => (string) $payment->folio_id, 'bookingStatus' => $payment->lifecycle_state,
            'paymentStatus' => $payment->status, 'method' => $payment->method, 'amountMinor' => (int) $payment->amount_minor,
            'recordedLocalDate' => $date, 'closedDateOrLaterExists' => DB::table('financial_day_closes')
                ->where('hotel_id', $hotel->id)->where('business_date', '>=', $date)->exists(),
            'signedFolio' => DB::table('folio_settlement_signoffs')->where('hotel_id', $hotel->id)
                ->where('folio_id', $payment->folio_id)->exists(),
            'relatedActivityCounts' => $activity];
    }

    public static function action(string $reason): string
    {
        return match ($reason) {
            'payment_evidence_unresolved' => 'Compare original bank or cash evidence with the audit trail. Determine whether money moved; request independent finance review and a supported current-period correction if required. Never mark rejected evidence verified merely to clear closing.',
            'invalid_or_unbalanced_journal', 'journal_mismatch', 'missing_journal', 'orphaned_journal',
            'orphaned_reversal', 'reversed_journal', 'duplicate_journal', 'duplicate_reversal',
            'duplicate_posting_key' => 'Trace original source and journal lines; reconcile the actual economic event and request an approved compensating posting in an open period where supported. Do not edit or delete historical journals.',
            'supplier_payable_not_reconciled' => 'Reconcile supplier invoices and payment records against accounts payable journal movements; investigate differences before any correction.',
            'employee_payable_not_reconciled' => 'Reconcile approved employee claims and reimbursements against employee payable journal movements.',
            'cashier_count_snapshot_mismatch', 'invalid_cashier_reconciliation', 'cashier_source_inconsistent' => 'Compare original cash count, receipts, change, payouts and shift events. Escalate to independent finance review; preserve the submitted count.',
            'signed_folio_snapshot_mismatch', 'folio_payment_overallocated_or_unverified' => 'Compare immutable settlement facts with payment, allocation and refund evidence. Request an approved correction without rewriting the signed statement.',
            'duplicate_bank_verification_reference' => 'Compare both payment records with independent bank evidence and investigate duplicate recognition before correcting any posting.',
            'business_clock_not_open', 'business_date_already_closed', 'open_accounting_period_count_invalid' => 'Review the business clock and accounting periods with finance. Never reopen an immutable financial day to clear this finding.',
            'invalid_clearance', 'correction_requires_resolution', 'correction_review_missing' => 'Review the no-show policy, clearance and existing correction proposals using the independent review workflow.',
            default => 'Investigate the original records and obtain independent finance review before proposing a supported correction.',
        };
    }

    public static function fingerprint(array $facts): string
    {
        return hash('sha256', json_encode(self::canonical($facts), JSON_THROW_ON_ERROR));
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }
        return $value;
    }
}

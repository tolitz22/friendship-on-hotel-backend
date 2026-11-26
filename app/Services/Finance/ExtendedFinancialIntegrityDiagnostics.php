<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\CashierShiftService;
use App\Domain\Reservations\FolioSettlementService;
use App\Http\Controllers\Api\FinancialBusinessDayController;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ExtendedFinancialIntegrityDiagnostics
{
    public function report(Hotel $hotel, ?string $date, ?int $actor): array
    {
        $id = (int) $hotel->id;
        $issues = [];
        $checks = ['folio_allocation_integrity', 'cashier_count_integrity', 'duplicate_posting_keys',
            'duplicate_bank_verification_references'];
        $notChecked = ['external_bank_confirmation', 'physical_cash_count'];
        $metrics = [];
        foreach (DB::table('reservation_folios')->where('hotel_id', $id)->lazyById(200) as $folio) {
            $booking = DB::table('reservation_records')->where('hotel_id', $id)->where('id', $folio->reservation_record_id)->first();
            if ($booking === null) {
                $issues[] = ['reason' => 'folio_booking_missing', 'folioId' => (string) $folio->id];
                continue;
            }
            $statement = app(FolioSettlementService::class)->statement($hotel, (int) $booking->id);
            foreach (self::folioFindings($statement) as $reason) {
                $issues[] = ['reason' => $reason, 'folioId' => (string) $folio->id, 'bookingId' => (string) $booking->id];
            }
        }
        foreach (DB::table('cashier_shifts')->where('hotel_id', $id)->lazyById(200) as $shift) {
            try {
                $expected = app(CashierShiftService::class)->integrityExpectation($hotel, (int) $shift->id);
                if ($shift->status !== 'open' && ($shift->expected_cash_minor === null
                    || $shift->counted_cash_minor === null || $shift->variance_minor === null
                    || (int) $shift->expected_cash_minor !== $expected
                    || (int) $shift->counted_cash_minor - $expected !== (int) $shift->variance_minor)) {
                    $issues[] = ['reason' => 'cashier_count_snapshot_mismatch', 'shiftId' => (string) $shift->id];
                }
                if ($shift->status === 'reconciled' && ($expected < 0 || (int) $shift->variance_minor !== 0
                    || $shift->reviewed_by === null || (int) $shift->reviewed_by === (int) $shift->cashier_id)) {
                    $issues[] = ['reason' => 'invalid_cashier_reconciliation', 'shiftId' => (string) $shift->id];
                }
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 409) {
                    throw $exception;
                }
                $issues[] = ['reason' => 'cashier_source_inconsistent', 'shiftId' => (string) $shift->id];
            }
        }
        $duplicates = DB::table('financial_posting_journals')->where('hotel_id', $id)
            ->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->get();
        foreach ($duplicates as $duplicate) {
            $issues[] = ['reason' => 'duplicate_posting_key', 'journalIds' => DB::table('financial_posting_journals')
                ->where('hotel_id', $id)->where('idempotency_key', $duplicate->idempotency_key)
                ->orderBy('id')->pluck('id')->map(fn ($value) => (string) $value)->all()];
        }
        $duplicates = DB::table('folio_settlement_verifications')->where('hotel_id', $id)
            ->where('evidence_kind', 'bank_statement')->whereIn('status', ['pending', 'verified'])
            ->select('evidence_reference')->groupBy('evidence_reference')
            ->havingRaw('COUNT(DISTINCT payment_record_id) > 1')->get();
        foreach ($duplicates as $duplicate) {
            $issues[] = ['reason' => 'duplicate_bank_verification_reference', 'paymentIds' => DB::table('folio_settlement_verifications')
                ->where('hotel_id', $id)->where('evidence_kind', 'bank_statement')->whereIn('status', ['pending', 'verified'])
                ->where('evidence_reference', $duplicate->evidence_reference)->orderBy('payment_record_id')
                ->pluck('payment_record_id')->map(fn ($value) => (string) $value)->all()];
        }

        if ($date !== null) {
            foreach (['supplier' => SupplierPayableAging::class, 'employee' => EmployeePayableReport::class] as $kind => $service) {
                $report = app($service)->report($id, $date);
                $metrics[$kind.'Payable'] = array_intersect_key($report, array_flip([
                    'outstandingMinor', 'signedSubledgerMinor', 'ledgerBalanceMinor', 'differenceMinor', 'inconsistentCount', 'reconciled',
                ]));
                $checks[] = $kind.'_payable_reconciliation';
                if (!$report['reconciled']) {
                    $issues[] = ['reason' => $kind.'_payable_not_reconciled', 'details' => $metrics[$kind.'Payable']];
                }
            }
        } else {
            $notChecked[] = 'supplier_and_employee_payables';
        }
        $closing = null;
        if ($date !== null && $actor !== null) {
            // Reuse the actual GET readiness implementation; never call close().
            $request = Request::create('/', 'GET');
            $request->setUserResolver(static fn () => (object) ['id' => $actor]);
            $response = app(FinancialBusinessDayController::class)->show($hotel, $request);
            $data = $response->getData(true)['data'];
            $closing = ['canClose' => $data['canClose'], 'blockers' => $data['blockers'], 'openShiftIds' => $data['openShiftIds']];
            $checks[] = 'daily_closing_advisory_readiness';
        } else {
            $notChecked[] = 'daily_closing_advisory_readiness';
        }

        return compact('issues', 'checks', 'notChecked', 'metrics', 'closing');
    }

    public static function folioFindings(array $statement): array
    {
        $issues = [];
        if ($statement['signed'] && !$statement['signedSnapshotIntegrity']) {
            $issues[] = 'signed_folio_snapshot_mismatch';
        }
        $verified = [];
        foreach ($statement['payments'] as $payment) {
            if ($payment['status'] === 'staff_confirmed' && $payment['verificationStatus'] === 'verified') {
                $verified[$payment['id']] = $payment['amountMinor'];
            }
        }
        foreach ($statement['allocations'] as $allocation) {
            $key = $allocation['paymentId'];
            $verified[$key] = ($verified[$key] ?? 0) - $allocation['amountMinor'];
        }
        foreach ($statement['refunds'] as $refund) {
            if ($refund['status'] === 'approved' && $refund['evidenceStatus'] === 'verified') {
                $key = $refund['paymentId'];
                $verified[$key] = ($verified[$key] ?? 0) - $refund['amountMinor'];
            }
        }
        if (min([0, ...array_values($verified)]) < 0) {
            $issues[] = 'folio_payment_overallocated_or_unverified';
        }

        return $issues;
    }
}

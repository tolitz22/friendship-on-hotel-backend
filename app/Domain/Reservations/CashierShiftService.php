<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CashierShiftService
{
    private function lockHotel(Hotel $hotel): void
    {
        app(ReadinessWriteGuard::class)->lockHotel($hotel);
    }

    private function shift(Hotel $hotel, int $id, bool $lock = false): object
    {
        $query = DB::table('cashier_shifts')->where('hotel_id', $hotel->id)->where('id', $id);
        $row = $lock ? $query->lockForUpdate()->first() : $query->first();
        abort_unless($row !== null, 404);
        return $row;
    }

    private function event(Hotel $hotel, int $shiftId, int $actor, string $event, array $details): void
    {
        DB::table('cashier_shift_events')->insert([
            'hotel_id' => $hotel->id, 'shift_id' => $shiftId, 'actor_id' => $actor,
            'event' => $event, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function checkedAdd(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > 9007199254740991 - $b) {
            throw new HttpException(422, 'Cash total exceeds supported financial limits.');
        }
        return $a + $b;
    }

    private function expected(Hotel $hotel, object $shift): array
    {
        $receipts = DB::table('cashier_shift_receipts as sr')
            ->join('folio_payment_records as p', 'p.id', '=', 'sr.payment_id')
            ->where('sr.hotel_id', $hotel->id)
            ->where('sr.shift_id', $shift->id)
            ->orderBy('sr.id')
            ->get(['sr.id','sr.payment_id','sr.reservation_record_id','sr.amount_minor',
                'sr.cash_tendered_minor','sr.change_given_minor','sr.net_receipt_minor',
                'p.status','p.reference']);
        $amount = (int) $shift->opening_float_minor;
        $unconfirmed = 0;
        foreach ($receipts as $receipt) {
            $tenderKnown = $receipt->cash_tendered_minor !== null;
            $changeKnown = $receipt->change_given_minor !== null;
            $netKnown = $receipt->net_receipt_minor !== null;
            if ($tenderKnown || $changeKnown || $netKnown) {
                if (! $tenderKnown || ! $changeKnown || ! $netKnown
                    || (int) $receipt->net_receipt_minor !== (int) $receipt->amount_minor
                    || (int) $receipt->cash_tendered_minor < (int) $receipt->net_receipt_minor
                    || (int) $receipt->cash_tendered_minor - (int) $receipt->change_given_minor
                        !== (int) $receipt->net_receipt_minor) {
                    throw new HttpException(409,
                        'Cash receipt tender, change or net amount has changed or is inconsistent. Investigate before reconciliation.');
                }
            }
            // Keep voided receipts in the immutable shift history, but never
            // treat a manager-voided mistake as physically received cash.
            if ($receipt->status === 'voided') {
                continue;
            }
            $amount = $this->checkedAdd($amount, (int) $receipt->amount_minor);
            if ($receipt->status !== 'staff_confirmed') {
                $unconfirmed++;
            }
        }
        $payouts = DB::table('cashier_expense_payouts as p')
            ->leftJoin('hotel_expenses as e', 'e.id', '=', 'p.expense_id')
            ->where('p.hotel_id', $hotel->id)->where('p.shift_id', $shift->id)
            ->orderBy('p.id')->get(['p.id', 'p.expense_id', 'p.amount_minor',
                'e.hotel_id as expense_hotel_id', 'e.amount_minor as expense_amount_minor',
                'e.payment_source', 'e.currency', 'e.recorded_by', 'e.payee', 'e.description',
                'e.department', 'e.category', 'e.review_status', 'p.created_at']);
        $payoutTotal = 0;
        foreach ($payouts as $payout) {
            if ((int) $payout->expense_hotel_id !== (int) $hotel->id
                || (int) $payout->recorded_by !== (int) $shift->cashier_id
                || $payout->payment_source !== 'cashier_drawer'
                || $payout->currency !== $shift->currency
                || (int) $payout->amount_minor <= 0
                || (int) $payout->amount_minor !== (int) $payout->expense_amount_minor) {
                throw new HttpException(409, 'Expense payout is inconsistent. Investigate before reconciliation.');
            }
            $payoutTotal = $this->checkedAdd($payoutTotal, (int) $payout->amount_minor);
        }
        $returns = DB::table('expense_cash_returns as r')
            ->leftJoin('hotel_expenses as e', 'e.id', '=', 'r.expense_id')
            ->leftJoin('expense_cash_return_reviews as v', function ($join): void {
                $join->on('v.cash_return_id', '=', 'r.id')->on('v.hotel_id', '=', 'r.hotel_id');
            })
            ->where('r.hotel_id', $hotel->id)->where('r.shift_id', $shift->id)
            ->orderBy('r.id')->get(['r.id', 'r.expense_id', 'r.amount_minor', 'r.recorded_by',
                'r.evidence_reference', 'r.created_at', 'e.hotel_id as expense_hotel_id',
                'e.currency', 'e.payee', 'v.decision']);
        $returnTotal = 0;
        foreach ($returns as $return) {
            if ((int) $return->expense_hotel_id !== (int) $hotel->id
                || (int) $return->recorded_by !== (int) $shift->cashier_id
                || $return->currency !== $shift->currency || (int) $return->amount_minor <= 0) {
                throw new HttpException(409, 'Cash return is inconsistent. Investigate before reconciliation.');
            }
            $returnTotal = $this->checkedAdd($returnTotal, (int) $return->amount_minor);
        }
        $amount = $this->checkedAdd($amount, $returnTotal);
        // Preserve an impossible negative expectation as a discrepancy rather
        // than hiding actual payouts or preventing the cashier from counting.
        $supplierPayments = DB::table('supplier_bill_payments as p')
            ->leftJoin('supplier_bills as b', 'b.id', '=', 'p.bill_id')
            ->where('p.hotel_id', $hotel->id)->where('p.shift_id', $shift->id)
            ->orderBy('p.id')->get(['p.id', 'p.bill_id', 'p.amount_minor', 'p.payment_source',
                'p.recorded_by', 'p.evidence_reference', 'p.created_at', 'b.hotel_id as bill_hotel_id',
                'b.currency', 'b.supplier_name_snapshot', 'b.invoice_reference']);
        $supplierTotal = 0;
        foreach ($supplierPayments as $payment) {
            if ((int) $payment->bill_hotel_id !== (int) $hotel->id
                || (int) $payment->recorded_by !== (int) $shift->cashier_id
                || $payment->payment_source !== 'cashier_drawer'
                || $payment->currency !== $shift->currency || (int) $payment->amount_minor <= 0) {
                throw new HttpException(409, 'Supplier payment is inconsistent. Investigate before reconciliation.');
            }
            $supplierTotal = $this->checkedAdd($supplierTotal, (int) $payment->amount_minor);
        }
        $reimbursements = DB::table('employee_reimbursement_payments as p')
            ->leftJoin('employee_expense_claims as c', 'c.id', '=', 'p.claim_id')
            ->where('p.hotel_id', $hotel->id)->where('p.shift_id', $shift->id)
            ->orderBy('p.id')->get(['p.id', 'p.claim_id', 'p.amount_minor', 'p.payment_source',
                'p.recorded_by', 'p.evidence_reference', 'p.created_at', 'c.hotel_id as claim_hotel_id',
                'c.currency', 'c.employee_name_snapshot', 'c.description']);
        $reimbursementTotal = 0;
        foreach ($reimbursements as $payment) {
            abort_unless((int) $payment->claim_hotel_id === (int) $hotel->id
                && (int) $payment->recorded_by === (int) $shift->cashier_id
                && $payment->payment_source === 'cashier_drawer'
                && $payment->currency === $shift->currency && (int) $payment->amount_minor > 0,
                409, 'Employee reimbursement is inconsistent. Investigate before reconciliation.');
            $reimbursementTotal = $this->checkedAdd($reimbursementTotal, (int) $payment->amount_minor);
        }
        $amount -= $this->checkedAdd($this->checkedAdd($payoutTotal, $supplierTotal), $reimbursementTotal);
        return [
            'employeeReimbursementTotalMinor' => $reimbursementTotal,
            'employeeReimbursementCount' => $reimbursements->count(),
            'employeeReimbursements' => $reimbursements->map(fn ($p) => [
                'id' => (string) $p->id, 'claimId' => (string) $p->claim_id,
                'amountMinor' => (int) $p->amount_minor, 'employeeName' => $p->employee_name_snapshot,
                'description' => $p->description, 'evidenceReference' => $p->evidence_reference,
                'createdAt' => $p->created_at,
            ])->all(),
            'supplierPaymentTotalMinor' => $supplierTotal,
            'supplierPaymentCount' => $supplierPayments->count(),
            'supplierPayments' => $supplierPayments->map(fn ($p) => [
                'id' => (string) $p->id, 'billId' => (string) $p->bill_id,
                'amountMinor' => (int) $p->amount_minor, 'supplierName' => $p->supplier_name_snapshot,
                'invoiceReference' => $p->invoice_reference, 'evidenceReference' => $p->evidence_reference,
                'createdAt' => $p->created_at,
            ])->all(),
            'expenseCashReturnTotalMinor' => $returnTotal,
            'expenseCashReturnCount' => $returns->count(),
            'unreviewedExpenseCashReturnCount' => $returns->filter(fn ($r) => $r->decision !== 'approve')->count(),
            'expenseCashReturns' => $returns->map(fn ($r) => [
                'id' => (string) $r->id, 'expenseId' => (string) $r->expense_id,
                'amountMinor' => (int) $r->amount_minor, 'payee' => $r->payee,
                'evidenceReference' => $r->evidence_reference, 'reviewStatus' => $r->decision === 'approve' ? 'approved' : ($r->decision === 'flag' ? 'flagged' : 'pending'),
                'createdAt' => $r->created_at,
            ])->all(),
            'expensePayoutTotalMinor' => $payoutTotal,
            'expensePayoutCount' => $payouts->count(),
            'expensePayouts' => $payouts->map(fn (object $p): array => [
                'id' => (string) $p->id,
                'expenseId' => (string) $p->expense_id,
                'amountMinor' => (int) $p->amount_minor,
                'payee' => $p->payee,
                'description' => $p->description,
                'department' => $p->department,
                'category' => $p->category,
                'reviewStatus' => $p->review_status,
                'createdAt' => $p->created_at,
            ])->all(),
            'expectedCashMinor' => $amount,
            'receiptCount' => $receipts->where('status', '!=', 'voided')->count(),
            'unconfirmedReceiptCount' => $unconfirmed,
            'receipts' => $receipts->map(fn (object $p): array => [
                'id' => (string) $p->id,
                'paymentId' => (string) $p->payment_id,
                'bookingId' => (string) $p->reservation_record_id,
                'reference' => $p->reference,
                'amountMinor' => (int) $p->amount_minor,
                // Historical receipts legitimately have no tender/change breakdown.
                'cashTenderedMinor' => $p->cash_tendered_minor === null
                    ? null : (int) $p->cash_tendered_minor,
                'changeGivenMinor' => $p->change_given_minor === null
                    ? null : (int) $p->change_given_minor,
                'netReceiptMinor' => $p->net_receipt_minor === null
                    ? null : (int) $p->net_receipt_minor,
                'paymentStatus' => $p->status,
            ])->all(),
        ];
    }

    /** Internal diagnostic only: never expose the blind cash count to staff. */
    public function integrityExpectation(Hotel $hotel, int $id): int
    {
        return $this->expected($hotel, $this->shift($hotel, $id))['expectedCashMinor'];
    }

    private function present(Hotel $hotel, object $shift, bool $includeReceipts = false): array
    {
        $calc = $this->expected($hotel, $shift);
        return [
            'id' => (string) $shift->id, 'hotelId' => (string) $shift->hotel_id,
            'cashierId' => (string) $shift->cashier_id,
            'currency' => $shift->currency, 'status' => $shift->status,
            'cashTenderTrackingSupported' => true,
            'expensePostingEnabled' => (bool) config('financial_accounting.enable_expense_posting', false),
            'expenseCorrectionsEnabled' => (bool) config('financial_accounting.enable_expense_corrections', false),
            'negativeExpectedCash' => $shift->status !== 'open' && (int) $shift->expected_cash_minor < 0,
            'openingFloatMinor' => (int) $shift->opening_float_minor,
            // Do not expose the live expected cash to the cashier before count submission.
            'expectedCashMinor' => $shift->status === 'open' ? null : (int) $shift->expected_cash_minor,
            'countedCashMinor' => $shift->counted_cash_minor === null ? null : (int) $shift->counted_cash_minor,
            'varianceMinor' => $shift->variance_minor === null ? null : (int) $shift->variance_minor,
            'employeeReimbursementTotalMinor' => $calc['employeeReimbursementTotalMinor'],
            'employeeReimbursementCount' => $calc['employeeReimbursementCount'],
            'supplierPaymentTotalMinor' => $calc['supplierPaymentTotalMinor'],
            'supplierPaymentCount' => $calc['supplierPaymentCount'],
            'expenseCashReturnTotalMinor' => $calc['expenseCashReturnTotalMinor'],
            'expenseCashReturnCount' => $calc['expenseCashReturnCount'],
            'unreviewedExpenseCashReturnCount' => $calc['unreviewedExpenseCashReturnCount'],
            'expensePayoutTotalMinor' => $calc['expensePayoutTotalMinor'],
            'expensePayoutCount' => $calc['expensePayoutCount'],
            'receiptCount' => $calc['receiptCount'],
            'unconfirmedReceiptCount' => $calc['unconfirmedReceiptCount'],
            'closingNote' => $shift->closing_note, 'managerNote' => $shift->manager_note,
            'reviewedBy' => $shift->reviewed_by === null ? null : (string) $shift->reviewed_by,
            'openedAt' => $shift->opened_at, 'submittedAt' => $shift->submitted_at,
            'reconciledAt' => $shift->reconciled_at,
            ...($includeReceipts ? ['receipts' => $calc['receipts'], 'expensePayouts' => $calc['expensePayouts'], 'expenseCashReturns' => $calc['expenseCashReturns'], 'supplierPayments' => $calc['supplierPayments'], 'employeeReimbursements' => $calc['employeeReimbursements']] : []),
        ];
    }

    public function listing(Hotel $hotel, int $actor, bool $manager, array $filters): array
    {
        $query = DB::table('cashier_shifts')->where('hotel_id', $hotel->id);
        if (! $manager) $query->where('cashier_id', $actor);
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20));
        return [
            'data' => $page->getCollection()->map(fn (object $row): array => $this->present($hotel, $row))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage()],
        ];
    }

    public function details(Hotel $hotel, int $actor, bool $manager, int $id): array
    {
        $row = $this->shift($hotel, $id);
        abort_if(! $manager && (int) $row->cashier_id !== $actor, 404);
        return $this->present($hotel, $row, true);
    }

    public function history(Hotel $hotel, int $actor, bool $manager, int $id): array
    {
        $this->details($hotel, $actor, $manager, $id);
        return DB::table('cashier_shift_events')
            ->leftJoin('users as audit_actor', 'audit_actor.id', '=', 'cashier_shift_events.actor_id')
            ->where('cashier_shift_events.hotel_id', $hotel->id)
            ->where('cashier_shift_events.shift_id', $id)->orderBy('cashier_shift_events.id')
            ->get(['cashier_shift_events.*', 'audit_actor.name as actor_name'])->map(fn (object $row): array => [
                'id' => (string) $row->id, 'actorId' => (string) $row->actor_id,
                'actorName' => $row->actor_name,
                'event' => $row->event, 'details' => json_decode($row->details, true),
                'createdAt' => $row->created_at,
            ])->all();
    }

    public function open(Hotel $hotel, int $actor, int $float): array
    {
        return DB::transaction(function () use ($hotel, $actor, $float): array {
            $this->lockHotel($hotel);
            $exists = DB::table('cashier_shifts')->where('hotel_id', $hotel->id)
                ->where('cashier_id', $actor)->whereIn('status', ['open','submitted'])->exists();
            if ($exists) throw new HttpException(409, 'Close and reconcile your previous shift before opening another.');
            $currency = strtoupper((string) ($hotel->currency ?: 'PHP'));
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new HttpException(422, 'Hotel currency is invalid.');
            }
            $id = DB::table('cashier_shifts')->insertGetId([
                'hotel_id' => $hotel->id, 'cashier_id' => $actor, 'currency' => $currency,
                'status' => 'open', 'opening_float_minor' => $float, 'opened_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'opened', ['openingFloatMinor' => $float]);
            return $this->present($hotel, $this->shift($hotel, $id));
        }, 3);
    }

    public function receive(Hotel $hotel, int $actor, int $shiftId, int $bookingId, array $data): array
    {
        return DB::transaction(function () use ($hotel, $actor, $shiftId, $bookingId, $data): array {
            $this->lockHotel($hotel);
            $shift = $this->shift($hotel, $shiftId, true);
            if ($shift->status !== 'open' || (int) $shift->cashier_id !== $actor) {
                throw new HttpException(403, 'Only the owning cashier can record cash in their open shift.');
            }
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId);
            $lifecycle = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->value('lifecycle_state');
            if (! in_array($lifecycle, ['confirmed', 'checked_in', 'checked_out'], true)) {
                throw new HttpException(409, 'Cash collection requires an active or completed registered stay.');
            }
            if ($folio->currency !== $shift->currency) {
                throw new HttpException(409, 'Folio currency does not match the shift.');
            }
            // Net receipt is the booked payment and drawer increase. Change is
            // physically paid back to the guest, never hotel revenue.
            $hasTender = array_key_exists('cashTenderedMinor', $data);
            $hasChange = array_key_exists('changeGivenMinor', $data);
            if ($hasTender !== $hasChange) {
                throw new HttpException(422, 'Enter cash tendered and change given together.');
            }
            if ($hasTender) {
                $net = (int) $data['amountMinor'];
                $tendered = (int) $data['cashTenderedMinor'];
                $change = (int) $data['changeGivenMinor'];
                if ($net < 1 || $tendered < $net || $change < 0 || $change !== $tendered - $net) {
                    throw new HttpException(422, 'Cash tendered minus change given must equal the recorded payment.');
                }
            }
            // The legacy service retains reference uniqueness, idempotency and
            // pending independent financial review. Never bypass its validation.
            $payment = app(FolioAccountingService::class)->recordPayment($hotel, $bookingId, $actor, [
                'requestKey' => $data['requestKey'], 'method' => 'cash',
                'reference' => $data['reference'], 'amountMinor' => $data['amountMinor'],
                'duplicateOverrideAuthorized' => $data['duplicateOverrideAuthorized'] ?? false,
                ...(! empty($data['duplicateOverrideReason'])
                    ? ['duplicateOverrideReason' => $data['duplicateOverrideReason']] : []),
            ]);
            $record = DB::table('folio_payment_records')
                ->where('hotel_id', $hotel->id)->where('id', (int) $payment['id'])->first();
            abort_unless($record !== null, 404);
            if ((int) $record->recorded_by !== $actor) {
                throw new HttpException(409, 'Payment idempotency key belongs to a different cashier.');
            }
            $linked = DB::table('cashier_shift_receipts')
                ->where('hotel_id', $hotel->id)->where('payment_id', $record->id)->first();
            if ($payment['replayed'] && $linked === null) {
                throw new HttpException(409, 'An older unlinked cash payment requires manager investigation; it cannot be adopted into a shift.');
            }
            if ($linked !== null) {
                if ((int) $linked->shift_id !== $shiftId) {
                    throw new HttpException(409, 'Payment is already linked to another shift.');
                }
                // Same idempotency key must not silently replay a different
                // tender/change breakdown, even when net payment is identical.
                if ($hasTender && ((int) $linked->cash_tendered_minor !== $tendered
                    || (int) $linked->change_given_minor !== $change
                    || (int) $linked->net_receipt_minor !== $net)) {
                    throw new HttpException(409, 'Cash tender or change differs from the original receipt.');
                }
                if (! $hasTender && $linked->cash_tendered_minor !== null) {
                    throw new HttpException(409, 'A recorded cash breakdown must be preserved on retry.');
                }
                return ['paymentId' => (string) $record->id, 'shiftId' => (string) $shiftId,
                    'status' => $record->status, 'replayed' => true,
                    'cashTenderedMinor' => $linked->cash_tendered_minor === null
                        ? null : (int) $linked->cash_tendered_minor,
                    'changeGivenMinor' => $linked->change_given_minor === null
                        ? null : (int) $linked->change_given_minor,
                    'netReceiptMinor' => $linked->net_receipt_minor === null
                        ? null : (int) $linked->net_receipt_minor];
            }
            DB::table('cashier_shift_receipts')->insert([
                'hotel_id' => $hotel->id, 'shift_id' => $shiftId,
                'payment_id' => $record->id, 'reservation_record_id' => $bookingId,
                'amount_minor' => $data['amountMinor'],
                'cash_tendered_minor' => $hasTender ? $tendered : null,
                'change_given_minor' => $hasTender ? $change : null,
                'net_receipt_minor' => $hasTender ? $net : null,
                'created_at' => now(),
            ]);
            // In simplified cashier mode, registering an actual cash tender
            // in the employee's own open shift replaces routine manager
            // confirmation. This is NOT independent verification or settlement.
            $autoConfirmed = config('financial_accounting.simplified_cashier_mode', false)
                && $hasTender && $record->status === 'pending_review';
            if ($autoConfirmed) {
                DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                    ->where('id', $record->id)->update([
                        'status' => 'staff_confirmed',
                        'review_note' => 'Cash receipt captured in the owning cashier shift under simplified cash policy; independent shift reconciliation pending.',
                        'updated_at' => now(),
                    ]);
            }
            $this->event($hotel, $shiftId, $actor, 'cash_received', [
                'paymentId' => (string) $record->id, 'bookingId' => (string) $bookingId,
                'amountMinor' => (int) $record->amount_minor, 'reference' => $record->reference,
                'cashTenderedMinor' => $hasTender ? $tendered : null,
                'changeGivenMinor' => $hasTender ? $change : null,
                'netReceiptMinor' => $hasTender ? $net : null,
                'simplifiedCashPolicy' => $autoConfirmed,
            ]);
            return ['paymentId' => (string) $record->id, 'shiftId' => (string) $shiftId,
                'status' => $autoConfirmed ? 'staff_confirmed' : $record->status,
                'replayed' => false,
                'cashTenderedMinor' => $hasTender ? $tendered : null,
                'changeGivenMinor' => $hasTender ? $change : null,
                'netReceiptMinor' => $hasTender ? $net : null];
        }, 3);
    }

    public function close(Hotel $hotel, int $actor, int $id, int $count, ?string $note): array
    {
        return DB::transaction(function () use ($hotel, $actor, $id, $count, $note): array {
            $this->lockHotel($hotel);
            $shift = $this->shift($hotel, $id, true);
            if ((int) $shift->cashier_id !== $actor || $shift->status !== 'open') {
                throw new HttpException(409, 'Only the owning cashier can submit their open shift.');
            }
            $calc = $this->expected($hotel, $shift);
            $expected = $calc['expectedCashMinor'];
            $variance = $count - $expected;
            DB::table('cashier_shifts')->where('id', $id)->update([
                'status' => 'submitted', 'expected_cash_minor' => $expected,
                'counted_cash_minor' => $count, 'variance_minor' => $variance,
                'closing_note' => $note === null ? null : trim($note),
                'submitted_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'submitted', [
                'expectedCashMinor' => $expected, 'countedCashMinor' => $count,
                'varianceMinor' => $variance, 'receiptCount' => $calc['receiptCount'],
                'expensePayoutTotalMinor' => $calc['expensePayoutTotalMinor'],
                'expensePayoutCount' => $calc['expensePayoutCount'],
                'employeeReimbursementTotalMinor' => $calc['employeeReimbursementTotalMinor'],
            'employeeReimbursementCount' => $calc['employeeReimbursementCount'],
            'supplierPaymentTotalMinor' => $calc['supplierPaymentTotalMinor'],
                'supplierPaymentCount' => $calc['supplierPaymentCount'],
                'unconfirmedReceiptCount' => $calc['unconfirmedReceiptCount'],
            ]);
            return $this->present($hotel, $this->shift($hotel, $id), true);
        }, 3);
    }

    public function reconcile(Hotel $hotel, int $manager, int $id, string $decision, string $note): array
    {
        return DB::transaction(function () use ($hotel, $manager, $id, $decision, $note): array {
            $this->lockHotel($hotel);
            $shift = $this->shift($hotel, $id, true);
            if ($shift->status !== 'submitted') throw new HttpException(409, 'Shift is not awaiting manager review.');
            if ((int) $shift->cashier_id === $manager) throw new HttpException(403, 'A cashier cannot reconcile their own shift.');
            $calc = $this->expected($hotel, $shift);
            if ($calc['expectedCashMinor'] !== (int) $shift->expected_cash_minor) {
                throw new HttpException(409, 'Shift receipts or payouts changed after submission.');
            }
            if ($decision === 'reconcile'
                && ((int) $shift->expected_cash_minor < 0 || (int) $shift->variance_minor !== 0 || $calc['unconfirmedReceiptCount'] > 0 || $calc['unreviewedExpenseCashReturnCount'] > 0)) {
                throw new HttpException(409, 'Variance or unreviewed/rejected payments require a flagged investigation.');
            }
            $status = $decision === 'reconcile' ? 'reconciled' : 'flagged';
            DB::table('cashier_shifts')->where('id', $id)->update([
                'status' => $status, 'reviewed_by' => $manager,
                'manager_note' => trim($note), 'reconciled_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $manager, $status, [
                'note' => trim($note), 'varianceMinor' => (int) $shift->variance_minor,
                'unconfirmedReceiptCount' => $calc['unconfirmedReceiptCount'],
            ]);
            return $this->present($hotel, $this->shift($hotel, $id), true);
        }, 3);
    }
}

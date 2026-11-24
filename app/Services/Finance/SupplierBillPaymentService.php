<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupplierBillPaymentService
{
    public function record(Hotel $hotel, int $actor, int $billId, array $input): array
    {
        abort_unless(config('financial_accounting.enable_supplier_billing', false), 409, 'Supplier billing is not enabled.');
        $data = Validator::make($input, [
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'paymentSource' => ['required', 'in:hotel_bank,cashier_drawer'],
            'evidenceReference' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'shiftId' => ['required_if:paymentSource,cashier_drawer', 'prohibited_if:paymentSource,hotel_bank', 'nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $billId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
                ->where('user_id', $actor)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
            $bill = DB::table('supplier_bills')->where('hotel_id', $hotel->id)
                ->where('id', $billId)->lockForUpdate()->first();
            abort_unless($bill !== null, 404);
            abort_unless($bill->currency === 'PHP' && ($hotel->currency ?? 'PHP') === 'PHP', 409);
            $facts = [
                'hotel_id' => (int) $hotel->id, 'bill_id' => $billId, 'recorded_by' => $actor,
                'request_key' => $data['requestKey'], 'amount_minor' => (int) $data['amountMinor'],
                'payment_source' => $data['paymentSource'], 'shift_id' => isset($data['shiftId']) ? (int) $data['shiftId'] : null,
                'evidence_reference' => trim($data['evidenceReference']),
            ];
            $base = DB::table('supplier_bill_payments')->where('hotel_id', $hotel->id);
            $existing = (clone $base)->where('request_key', $data['requestKey'])->first();
            $paid = (int) (clone $base)->where('bill_id', $billId)->sum('amount_minor');
            $outstanding = (int) $bill->amount_minor - $paid;
            abort_if($outstanding < 0, 409, 'Payable ledger requires investigation.');
            if ($existing !== null) {
                foreach ($facts as $key => $value) {
                    abort_unless(($existing->$key === null) === ($value === null)
                        && (string) $existing->$key === (string) $value, 409, 'Payment request key already used with different details.');
                }
                return $this->result($bill, (int) $existing->id, (int) $existing->journal_id, $paid, true);
            }
            abort_if($facts['amount_minor'] > $outstanding, 409, 'Payment exceeds the outstanding invoice balance.');
            if ($facts['payment_source'] === 'cashier_drawer') {
                $shift = DB::table('cashier_shifts')->where('hotel_id', $hotel->id)
                    ->where('id', $facts['shift_id'])->lockForUpdate()->first();
                abort_unless($shift !== null && (int) $shift->cashier_id === $actor, 404);
                abort_unless($shift->status === 'open' && $shift->currency === 'PHP', 409, 'An open PHP cashier shift is required.');
            }
            // Record only a payment already made; no bank transfer is initiated.
            // Evidence references may cover several invoices, but reuse against
            // the same bill requires investigation rather than silent duplication.
            abort_if((clone $base)->where('bill_id', $billId)
                ->whereRaw('LOWER(evidence_reference) = LOWER(?)', [$facts['evidence_reference']])->exists(),
                409, 'This payment evidence is already recorded for the invoice.');
            $journal = app(FinancialJournalPostingService::class)->post(
                (int) $hotel->id, 'supplier-payment:'.$data['requestKey'], 'supplier_bill_payment',
                $data['requestKey'], 'Supplier payment recorded from evidence', [
                    ['account_code' => 'accounts_payable', 'debit_minor' => $facts['amount_minor'], 'credit_minor' => 0],
                    ['account_code' => $facts['payment_source'] === 'cashier_drawer' ? 'cashier_cash' : 'hotel_bank', 'debit_minor' => 0, 'credit_minor' => $facts['amount_minor']],
                ], $actor
            );
            $date = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('id', $journal)->value('business_date');
            $payment = DB::table('supplier_bill_payments')->insertGetId($facts + [
                'journal_id' => $journal, 'business_date' => $date, 'created_at' => now(),
            ]);
            if ($facts['shift_id'] !== null) {
                DB::table('cashier_shift_events')->insert([
                    'hotel_id' => $hotel->id, 'shift_id' => $facts['shift_id'], 'actor_id' => $actor,
                    'event' => 'supplier_bill_payment', 'details' => json_encode([
                        'billId' => (string) $billId, 'paymentId' => (string) $payment,
                        'amountMinor' => $facts['amount_minor'], 'supplierName' => $bill->supplier_name_snapshot,
                        'invoiceReference' => $bill->invoice_reference,
                    ], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            }
            return $this->result($bill, (int) $payment, $journal, $paid + $facts['amount_minor'], false);
        }, 3);
    }

    private function result(object $bill, int $payment, int $journal, int $paid, bool $replayed): array
    {
        $remaining = (int) $bill->amount_minor - $paid;
        return ['billId' => (string) $bill->id, 'paymentId' => (string) $payment,
            'journalId' => (string) $journal, 'paidMinor' => $paid, 'outstandingMinor' => $remaining,
            'paymentStatus' => $remaining === 0 ? 'paid' : 'partially_paid', 'replayed' => $replayed];
    }
}

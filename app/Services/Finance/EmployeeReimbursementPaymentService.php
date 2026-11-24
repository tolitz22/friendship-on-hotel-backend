<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Http\Middleware\EnsureHotelPermission;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class EmployeeReimbursementPaymentService
{
    public function record(Hotel $hotel, int $actor, int $claimId, array $input): array
    {
        abort_unless(config('financial_accounting.enable_employee_reimbursements', false), 409, 'Employee reimbursements are not enabled.');
        $data = Validator::make($input, [
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'paymentSource' => ['required', 'in:hotel_bank,cashier_drawer'],
            'evidenceReference' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'shiftId' => ['required_if:paymentSource,cashier_drawer', 'prohibited_if:paymentSource,hotel_bank', 'nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $claimId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $membership = HotelMembership::query()->where('hotel_id', $hotel->id)
                ->where('user_id', $actor)->where('is_active', true)->lockForUpdate()->first();
            abort_unless($membership !== null && $membership->role === 'admin'
                && in_array('reimbursement.pay', EnsureHotelPermission::effectiveForMembership($membership), true)
                && User::query()->whereKey($actor)->where('is_active', true)->exists(), 403);
            $claim = DB::table('employee_expense_claims')->where('hotel_id', $hotel->id)
                ->where('id', $claimId)->lockForUpdate()->first();
            abort_unless($claim !== null, 404);
            abort_if((int) $claim->employee_id === $actor || (int) $claim->submitted_by === $actor,
                403, 'Another administrator must record reimbursement to this employee.');
            abort_unless($claim->currency === 'PHP' && ($hotel->currency ?? 'PHP') === 'PHP'
                && (int) $claim->amount_minor > 0, 409, 'Invalid claim accounting facts.');
            $decision = DB::table('employee_expense_claim_decisions')->where('hotel_id', $hotel->id)
                ->where('claim_id', $claimId)->first();
            abort_unless($decision !== null && $decision->decision === 'approve'
                && $decision->journal_id !== null && $decision->business_date !== null, 409, 'Only approved claims can be reimbursed.');
            $facts = [
                'hotel_id' => (int) $hotel->id, 'claim_id' => $claimId, 'recorded_by' => $actor,
                'request_key' => $data['requestKey'], 'amount_minor' => (int) $data['amountMinor'],
                'payment_source' => $data['paymentSource'], 'shift_id' => isset($data['shiftId']) ? (int) $data['shiftId'] : null,
                'evidence_reference' => trim(preg_replace('/\\s+/u', ' ', $data['evidenceReference'])),
            ];
            $base = DB::table('employee_reimbursement_payments')->where('hotel_id', $hotel->id);
            $existing = (clone $base)->where('request_key', $data['requestKey'])->first();
            $paid = (int) (clone $base)->where('claim_id', $claimId)->sum('amount_minor');
            $outstanding = (int) $claim->amount_minor - $paid;
            abort_if($outstanding < 0, 409, 'Employee payable requires investigation.');
            if ($existing !== null) {
                foreach ($facts as $key => $value) {
                    abort_unless(($existing->$key === null) === ($value === null)
                        && (string) $existing->$key === (string) $value, 409, 'Payment request key already used with different details.');
                }
                return $this->result($claim, (int) $existing->id, (int) $existing->journal_id, $paid, true);
            }
            abort_if($facts['amount_minor'] > $outstanding, 409, 'Payment exceeds the outstanding claim balance.');
            if ($facts['payment_source'] === 'cashier_drawer') {
                $shift = DB::table('cashier_shifts')->where('hotel_id', $hotel->id)
                    ->where('id', $facts['shift_id'])->lockForUpdate()->first();
                abort_unless($shift !== null && (int) $shift->cashier_id === $actor, 404);
                abort_unless($shift->status === 'open' && $shift->currency === 'PHP', 409, 'An open PHP cashier shift is required.');
            }
            // Initial release: a single evidence reference per payment in this property.
            // Bulk allocations require a separately controlled workflow.
            abort_if((clone $base)->where('payment_source', $facts['payment_source'])
                ->whereRaw('LOWER(evidence_reference) = LOWER(?)', [$facts['evidence_reference']])->exists(),
                409, 'This reimbursement payment evidence has already been recorded.');
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            abort_unless($clock !== null && $clock->status === 'open', 409, 'An open financial business date is required.');
            abort_if((string) $clock->business_date < (string) $decision->business_date, 409, 'Payment cannot precede claim approval.');
            $journal = app(FinancialJournalPostingService::class)->post(
                (int) $hotel->id, 'employee-payment:'.$data['requestKey'], 'employee_reimbursement_payment',
                (string) $claimId, 'Employee reimbursement recorded from payment evidence', [
                    ['account_code' => 'employee_payable', 'debit_minor' => $facts['amount_minor'], 'credit_minor' => 0],
                    ['account_code' => $facts['payment_source'] === 'cashier_drawer' ? 'cashier_cash' : 'hotel_bank', 'debit_minor' => 0, 'credit_minor' => $facts['amount_minor']],
                ], $actor
            );
            $date = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('id', $journal)->value('business_date');
            $payment = DB::table('employee_reimbursement_payments')->insertGetId($facts + [
                'journal_id' => $journal, 'business_date' => $date, 'created_at' => now(),
            ]);
            if ($facts['shift_id'] !== null) {
                DB::table('cashier_shift_events')->insert([
                    'hotel_id' => $hotel->id, 'shift_id' => $facts['shift_id'], 'actor_id' => $actor,
                    'event' => 'employee_reimbursement_payment', 'details' => json_encode([
                        'claimId' => (string) $claimId, 'paymentId' => (string) $payment,
                        'amountMinor' => $facts['amount_minor'], 'employeeName' => $claim->employee_name_snapshot,
                        'description' => $claim->description,
                    ], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            }
            return $this->result($claim, (int) $payment, $journal, $paid + $facts['amount_minor'], false);
        }, 3);
    }

    private function result(object $claim, int $payment, int $journal, int $paid, bool $replayed): array
    {
        $remaining = (int) $claim->amount_minor - $paid;
        return ['claimId' => (string) $claim->id, 'paymentId' => (string) $payment,
            'journalId' => (string) $journal, 'paidMinor' => $paid, 'outstandingMinor' => $remaining,
            'paymentStatus' => $remaining === 0 ? 'paid' : 'partially_paid', 'replayed' => $replayed];
    }
}

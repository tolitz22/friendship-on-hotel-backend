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

final class EmployeeExpenseClaimDecisionService
{
    public function decide(Hotel $hotel, int $actor, int $claimId, array $input): array
    {
        abort_unless(config('financial_accounting.enable_employee_reimbursements', false), 409, 'Employee reimbursements are not enabled.');
        $data = Validator::make($input, [
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'min:5', 'max:500', 'regex:/\\S/u'],
            'amountMinor' => ['prohibited'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $claimId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $membership = HotelMembership::query()->where('hotel_id', $hotel->id)
                ->where('user_id', $actor)->where('is_active', true)->lockForUpdate()->first();
            abort_unless($membership !== null && $membership->role === 'admin'
                && in_array('reimbursement.review', EnsureHotelPermission::effectiveForMembership($membership), true)
                && User::query()->whereKey($actor)->where('is_active', true)->exists(), 403);
            $claim = DB::table('employee_expense_claims')->where('hotel_id', $hotel->id)
                ->where('id', $claimId)->lockForUpdate()->first();
            abort_unless($claim !== null, 404);
            abort_if((int) $claim->employee_id === $actor || (int) $claim->submitted_by === $actor,
                403, 'An independent administrator must review this claim.');
            $facts = [
                'hotel_id' => (int) $hotel->id, 'claim_id' => $claimId,
                'reviewed_by' => $actor, 'request_key' => $data['requestKey'],
                'decision' => $data['decision'], 'note' => trim($data['note']),
            ];
            abort_if(mb_strlen($facts['note']) < 5, 422, 'A meaningful review note is required.');
            $base = DB::table('employee_expense_claim_decisions')->where('hotel_id', $hotel->id);
            $previous = (clone $base)->where('request_key', $data['requestKey'])->first();
            if ($previous !== null) {
                foreach ($facts as $key => $value) {
                    abort_unless((string) $previous->$key === (string) $value, 409, 'Review request key already used with different details.');
                }
                return $this->result($previous, true);
            }
            abort_if((clone $base)->where('claim_id', $claimId)->exists(), 409, 'This claim already has a final decision.');

            $journalId = null;
            $businessDate = null;
            if ($data['decision'] === 'approve') {
                $amount = (int) $claim->amount_minor;
                abort_unless($claim->currency === 'PHP' && ($hotel->currency ?? 'PHP') === 'PHP'
                    && $amount > 0 && $amount <= 100000000000, 409, 'Invalid claim accounting facts.');
                $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
                abort_unless($clock !== null && $clock->status === 'open', 409, 'An open financial business date is required.');
                abort_if((string) $claim->service_date > (string) $clock->business_date,
                    422, 'Expense date is later than the financial business date.');
                $journalId = app(FinancialJournalPostingService::class)->post(
                    (int) $hotel->id, 'employee-claim:'.$data['requestKey'], 'employee_expense_claim',
                    (string) $claimId, 'Approved employee operating expense claim', [
                        ['account_code' => 'operating_expense', 'debit_minor' => $amount, 'credit_minor' => 0],
                        ['account_code' => 'employee_payable', 'debit_minor' => 0, 'credit_minor' => $amount],
                    ], $actor
                );
                $businessDate = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                    ->where('id', $journalId)->value('business_date');
            }
            // Rejections are review events only and do not post a journal.
            $id = DB::table('employee_expense_claim_decisions')->insertGetId($facts + [
                'journal_id' => $journalId, 'business_date' => $businessDate, 'created_at' => now(),
            ]);
            $saved = DB::table('employee_expense_claim_decisions')->where('hotel_id', $hotel->id)->where('id', $id)->first();
            return $this->result($saved, false);
        }, 3);
    }

    private function result(object $decision, bool $replayed): array
    {
        return [
            'claimId' => (string) $decision->claim_id,
            'decisionId' => (string) $decision->id,
            'decision' => $decision->decision,
            'journalId' => $decision->journal_id === null ? null : (string) $decision->journal_id,
            'businessDate' => $decision->business_date === null ? null : (string) $decision->business_date,
            'replayed' => $replayed,
        ];
    }
}

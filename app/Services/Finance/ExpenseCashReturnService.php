<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Http\Middleware\EnsureHotelPermission;
use App\Models\Hotel;
use App\Models\HotelMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ExpenseCashReturnService
{
    private function authorize(Hotel $hotel, int $actor, bool $manager = false): void
    {
        $membership = HotelMembership::where('hotel_id', $hotel->id)
            ->where('user_id', $actor)->where('is_active', true)->first();
        abort_unless($membership !== null && DB::table('users')->where('id', $actor)
            ->where('is_active', true)->exists(), 403);
        if ($manager) {
            abort_unless($membership->role === 'admin', 403);
        } else {
            $permissions = EnsureHotelPermission::effectiveForMembership($membership);
            abort_unless(in_array('expense.record', $permissions, true)
                && in_array('cashier.write', $permissions, true), 403);
        }
    }

    public function record(Hotel $hotel, int $actor, int $expenseId, int $shiftId, array $input): array
    {
        $data = Validator::make($input, [
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'evidenceReference' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'reason' => ['required', 'string', 'min:5', 'max:500', 'regex:/\\S/u'],
            'correctionId' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $expenseId, $shiftId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorize($hotel, $actor);
            $expense = DB::table('hotel_expenses')->where('hotel_id', $hotel->id)
                ->where('id', $expenseId)->lockForUpdate()->first();
            abort_unless($expense !== null, 404);
            abort_unless($expense->currency === 'PHP' && $expense->payment_source === 'cashier_drawer', 409);
            $shift = DB::table('cashier_shifts')->where('hotel_id', $hotel->id)->where('id', $shiftId)
                ->where('cashier_id', $actor)->lockForUpdate()->first();
            abort_unless($shift !== null, 404);
            $amount = (int) $data['amountMinor'];
            $correctionId = isset($data['correctionId']) ? (int) $data['correctionId'] : null;
            $facts = [
                'hotel_id' => $hotel->id, 'expense_id' => $expenseId, 'shift_id' => $shiftId,
                'recorded_by' => $actor, 'correction_id' => $correctionId,
                'request_key' => $data['requestKey'], 'amount_minor' => $amount,
                'evidence_reference' => trim($data['evidenceReference']), 'reason' => trim($data['reason']),
            ];
            $existing = DB::table('expense_cash_returns')->where('hotel_id', $hotel->id)
                ->where('request_key', $data['requestKey'])->first();
            if ($existing !== null) {
                foreach ($facts as $key => $value) {
                    abort_unless(($existing->$key === null) === ($value === null)
                        && (string) $existing->$key === (string) $value, 409, 'Cash-return request key already used with different details.');
                }
                return ['cashReturnId' => (string) $existing->id, 'journalId' => (string) $existing->journal_id, 'replayed' => true];
            }
            abort_unless($shift->status === 'open' && $shift->currency === 'PHP', 409, 'An open PHP drawer is required.');
            $corrections = DB::table('hotel_expense_corrections as c')
                ->leftJoin('hotel_expense_correction_decisions as d', function ($join): void {
                    $join->on('d.correction_id', '=', 'c.id')->on('d.hotel_id', '=', 'c.hotel_id');
                })
                ->where('c.hotel_id', $hotel->id)->where('c.expense_id', $expenseId)
                ->where('c.kind', 'amount_correction')
                ->where(fn ($q) => $q->whereNull('d.id')->orWhere('d.decision', 'approve'))
                ->get(['c.id', 'c.corrected_amount_minor', 'd.decision', 'd.journal_id']);
            $returns = DB::table('expense_cash_returns')->where('hotel_id', $hotel->id)->where('expense_id', $expenseId);
            $returned = (int) (clone $returns)->sum('amount_minor');
            abort_if($returned > (int) $expense->amount_minor || $amount > (int) $expense->amount_minor - $returned, 409,
                'Cash returns cannot exceed the original recorded payout.');
            $account = 'operating_expense';
            if ($correctionId !== null) {
                $correction = $corrections->first(fn ($c) => (int) $c->id === $correctionId && $c->decision === 'approve');
                abort_unless($correction !== null && $correction->journal_id !== null, 409, 'An approved downward amount correction is required.');
                $clearing = (int) $expense->amount_minor - (int) $correction->corrected_amount_minor;
                $settled = (int) (clone $returns)->where('correction_id', $correctionId)->sum('amount_minor');
                abort_if($clearing <= 0 || $settled > $clearing || $amount > $clearing - $settled, 409,
                    'Cash return exceeds the unresolved debit clearing balance.');
                $account = 'expense_correction_clearing';
            } else {
                abort_if($corrections->isNotEmpty(), 409, 'Resolve the amount correction through its clearing balance first.');
            }
            // Physical cash is recorded immediately, even before independent review.
            // Clearing receipts must not credit expense a second time.
            $journalId = app(FinancialJournalPostingService::class)->post(
                (int) $hotel->id, 'expense-return:'.$data['requestKey'],
                $correctionId === null ? 'cash_expense_refund' : 'expense_clearing_cash_return',
                (string) $expenseId, 'Physical supplier cash return', [
                    ['account_code' => 'cashier_cash', 'debit_minor' => $amount, 'credit_minor' => 0],
                    ['account_code' => $account, 'debit_minor' => 0, 'credit_minor' => $amount],
                ], $actor
            );
            $date = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('id', $journalId)->value('business_date');
            $id = DB::table('expense_cash_returns')->insertGetId($facts + [
                'journal_id' => $journalId, 'business_date' => $date, 'created_at' => now(),
            ]);
            DB::table('cashier_shift_events')->insert([
                'hotel_id' => $hotel->id, 'shift_id' => $shiftId, 'actor_id' => $actor,
                'event' => 'expense_cash_return', 'details' => json_encode([
                    'cashReturnId' => (string) $id, 'expenseId' => (string) $expenseId,
                    'amountMinor' => $amount, 'evidenceReference' => $facts['evidence_reference'],
                ], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            return ['cashReturnId' => (string) $id, 'journalId' => (string) $journalId, 'replayed' => false];
        }, 3);
    }

    public function review(Hotel $hotel, int $actor, int $returnId, array $input): array
    {
        $data = Validator::make($input, [
            'decision' => ['required', 'in:approve,flag'],
            'note' => ['required', 'string', 'min:5', 'max:500', 'regex:/\\S/u'],
        ])->validate();
        return DB::transaction(function () use ($hotel, $actor, $returnId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorize($hotel, $actor, true);
            $row = DB::table('expense_cash_returns')->where('hotel_id', $hotel->id)
                ->where('id', $returnId)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            abort_if((int) $row->recorded_by === $actor, 403, 'You cannot review your own cash return.');
            $previous = DB::table('expense_cash_return_reviews')->where('hotel_id', $hotel->id)
                ->where('cash_return_id', $returnId)->first();
            if ($previous !== null) {
                abort_unless((int) $previous->reviewed_by === $actor && $previous->decision === $data['decision']
                    && $previous->note === trim($data['note']), 409, 'Cash return already reviewed.');
                return ['cashReturnId' => (string) $returnId, 'decision' => $previous->decision, 'replayed' => true];
            }
            DB::table('expense_cash_return_reviews')->insert([
                'hotel_id' => $hotel->id, 'cash_return_id' => $returnId, 'reviewed_by' => $actor,
                'decision' => $data['decision'], 'note' => trim($data['note']), 'created_at' => now(),
            ]);
            return ['cashReturnId' => (string) $returnId, 'decision' => $data['decision'], 'replayed' => false];
        }, 3);
    }
}

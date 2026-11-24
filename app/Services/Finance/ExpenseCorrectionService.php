<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ExpenseCorrectionService
{
    private function authorize(Hotel $hotel, int $actor): void
    {
        abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
            ->where('user_id', $actor)->where('role', 'admin')->where('is_active', true)->exists()
            && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
    }

    private function expense(Hotel $hotel, int $expenseId): object
    {
        $expense = DB::table('hotel_expenses')->where('hotel_id', $hotel->id)
            ->where('id', $expenseId)->lockForUpdate()->first();
        abort_unless($expense !== null, 404);
        abort_unless($expense->payment_source === 'cashier_drawer' && $expense->currency === 'PHP', 409,
            'This correction workflow supports PHP cashier expenses only.');
        return $expense;
    }

    public function request(Hotel $hotel, int $actor, int $expenseId, array $input): array
    {
        $data = Validator::make($input, [
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'kind' => ['required', 'in:amount_correction,reclassification'],
            'reason' => ['required', 'string', 'min:5', 'max:500', 'regex:/\\S/u'],
            'correctedAmountMinor' => ['required_if:kind,amount_correction', 'prohibited_unless:kind,amount_correction', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'correctedDepartment' => ['required_if:kind,reclassification', 'prohibited_unless:kind,reclassification', 'nullable', 'in:rooms,maintenance,administration,utilities,food_beverage'],
            'correctedCategory' => ['required_if:kind,reclassification', 'prohibited_unless:kind,reclassification', 'nullable', 'string', 'max:64', 'regex:/\\S/u'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $expenseId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorize($hotel, $actor);
            $expense = $this->expense($hotel, $expenseId);
            $facts = [
                'hotel_id' => $hotel->id, 'expense_id' => $expenseId, 'requested_by' => $actor,
                'request_key' => $data['requestKey'], 'kind' => $data['kind'], 'reason' => trim($data['reason']),
                'corrected_amount_minor' => isset($data['correctedAmountMinor']) ? (int) $data['correctedAmountMinor'] : null,
                'corrected_department' => $data['correctedDepartment'] ?? null,
                'corrected_category' => isset($data['correctedCategory']) ? trim($data['correctedCategory']) : null,
            ];
            $existing = DB::table('hotel_expense_corrections')->where('hotel_id', $hotel->id)
                ->where('request_key', $data['requestKey'])->first();
            if ($existing !== null) {
                foreach ($facts as $key => $value) {
                    abort_unless(($existing->$key === null) === ($value === null)
                        && (string) $existing->$key === (string) $value, 409, 'Correction request key already used with different details.');
                }
                return ['correctionId' => (string) $existing->id, 'replayed' => true];
            }
            // One approved correction per voucher in this initial release.
            // Rejected requests can be replaced; no concurrent pending requests.
            $unresolved = DB::table('hotel_expense_corrections as c')
                ->leftJoin('hotel_expense_correction_decisions as d', 'd.correction_id', '=', 'c.id')
                ->where('c.hotel_id', $hotel->id)->where('c.expense_id', $expenseId)
                ->where(fn ($q) => $q->whereNull('d.id')->orWhere('d.decision', 'approve'))->exists();
            abort_if($unresolved, 409, 'A pending or approved correction already exists for this voucher.');
            abort_if($data['kind'] === 'amount_correction' && $facts['corrected_amount_minor'] === (int) $expense->amount_minor,
                422, 'Corrected amount must differ from the original.');
            abort_if($data['kind'] === 'reclassification' && $facts['corrected_department'] === $expense->department
                && $facts['corrected_category'] === $expense->category, 422, 'Classification must change.');
            $id = DB::table('hotel_expense_corrections')->insertGetId($facts + ['created_at' => now()]);
            return ['correctionId' => (string) $id, 'replayed' => false];
        }, 3);
    }

    public function decide(Hotel $hotel, int $actor, int $correctionId, array $input): array
    {
        $data = Validator::make($input, [
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'min:5', 'max:500', 'regex:/\\S/u'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $correctionId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorize($hotel, $actor);
            $correction = DB::table('hotel_expense_corrections')->where('hotel_id', $hotel->id)
                ->where('id', $correctionId)->lockForUpdate()->first();
            abort_unless($correction !== null, 404);
            $expense = $this->expense($hotel, (int) $correction->expense_id);
            abort_if((int) $correction->requested_by === $actor || (int) $expense->recorded_by === $actor,
                403, 'An independent administrator must decide this correction.');
            $previous = DB::table('hotel_expense_correction_decisions')->where('hotel_id', $hotel->id)
                ->where('correction_id', $correctionId)->first();
            if ($previous !== null) {
                abort_unless((int) $previous->reviewed_by === $actor && $previous->decision === $data['decision']
                    && $previous->note === trim($data['note']), 409, 'Correction already decided.');
                return ['correctionId' => (string) $correctionId, 'decision' => $previous->decision,
                    'journalId' => $previous->journal_id === null ? null : (string) $previous->journal_id, 'replayed' => true];
            }
            $journalId = null;
            if ($data['decision'] === 'approve') {
                if ($correction->kind === 'amount_correction') {
                    $delta = (int) $correction->corrected_amount_minor - (int) $expense->amount_minor;
                    abort_if($delta === 0, 409, 'No accounting adjustment required.');
                    // A correction is not a cash receipt or payout. The suspense
                    // balance requires separate investigation and settlement.
                    $journalId = app(FinancialJournalPostingService::class)->post(
                        (int) $hotel->id, 'expense-correction:'.$correctionId, 'cash_expense_adjustment',
                        (string) $correctionId, 'Expense amount correction; clearing investigation required', [
                            ['account_code' => 'operating_expense', 'debit_minor' => max($delta, 0), 'credit_minor' => max(-$delta, 0)],
                            ['account_code' => 'expense_correction_clearing', 'debit_minor' => max(-$delta, 0), 'credit_minor' => max($delta, 0)],
                        ], $actor
                    );
                } else {
                    // Classification is an audited reporting overlay: the
                    // original general-ledger account and cash are unchanged.
                    $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
                    abort_unless($clock !== null && $clock->status === 'open'
                        && !DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                            ->where('business_date', $clock->business_date)->exists(), 409, 'Financial business date is not open.');
                }
            }
            DB::table('hotel_expense_correction_decisions')->insert([
                'hotel_id' => $hotel->id, 'correction_id' => $correctionId, 'reviewed_by' => $actor,
                'decision' => $data['decision'], 'note' => trim($data['note']), 'journal_id' => $journalId, 'created_at' => now(),
            ]);
            return ['correctionId' => (string) $correctionId, 'decision' => $data['decision'],
                'journalId' => $journalId === null ? null : (string) $journalId, 'replayed' => false];
        }, 3);
    }
}

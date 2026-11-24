<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CashExpensePostingService
{
    // Internal service only. Do not expose until reconciliation, permissions,
    // configured expense policy and end-to-end tests are integrated.
    public function record(Hotel $hotel, int $actor, int $shiftId, array $input): array
    {
        $data = Validator::make($input, [
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'payee' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'description' => ['required', 'string', 'max:255', 'regex:/\\S/u'],
            'department' => ['required', 'in:rooms,maintenance,administration,utilities,food_beverage'],
            'category' => ['required', 'string', 'max:64', 'regex:/\\S/u'],
            'serviceDate' => ['required', 'date_format:Y-m-d'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'invoiceReference' => ['nullable', 'string', 'max:120'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $shiftId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $shift = DB::table('cashier_shifts')->where('hotel_id', $hotel->id)
                ->where('id', $shiftId)->lockForUpdate()->first();
            abort_unless($shift !== null && (int) $shift->cashier_id === $actor, 404);
            $amount = (int) $data['amountMinor'];
            $facts = [
                'hotel_id' => $hotel->id,
                'recorded_by' => $actor,
                'request_key' => $data['requestKey'],
                'payee' => trim($data['payee']),
                'description' => trim($data['description']),
                'department' => $data['department'],
                'category' => trim($data['category']),
                'service_date' => $data['serviceDate'],
                'currency' => 'PHP',
                'amount_minor' => $amount,
                'payment_source' => 'cashier_drawer',
                'invoice_reference' => $data['invoiceReference'] ?? null,
            ];
            $existing = DB::table('hotel_expenses')->where('hotel_id', $hotel->id)
                ->where('request_key', $data['requestKey'])->first();
            if ($existing !== null) {
                foreach ($facts as $key => $value) {
                    if (($existing->$key === null) !== ($value === null)
                        || (string) $existing->$key !== (string) $value) {
                        throw new HttpException(409, 'Expense request key already used with different details.');
                    }
                }
                $payout = DB::table('cashier_expense_payouts')->where('hotel_id', $hotel->id)
                    ->where('expense_id', $existing->id)->first();
                if ($payout === null || (int) $payout->shift_id !== $shiftId
                    || (int) $payout->amount_minor !== $amount) {
                    throw new HttpException(409, 'Expense payout does not match the original request.');
                }
                return ['expenseId' => (string) $existing->id, 'payoutId' => (string) $payout->id,
                    'journalId' => (string) $existing->journal_id, 'replayed' => true];
            }
            abort_unless($shift->status === 'open' && $shift->currency === 'PHP', 409,
                'An open PHP cashier shift is required.');
            $journalId = app(FinancialJournalPostingService::class)->post(
                (int) $hotel->id, 'expense:'.$data['requestKey'], 'cash_expense',
                $data['requestKey'], 'Cash expense payout', [
                    ['account_code' => 'operating_expense', 'debit_minor' => $amount, 'credit_minor' => 0],
                    ['account_code' => 'cashier_cash', 'debit_minor' => 0, 'credit_minor' => $amount],
                ], $actor
            );
            $date = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                ->where('id', $journalId)->value('business_date');
            $policy = app(ExpensePolicyService::class)->policy($hotel);
            $limit = $policy['routineLimitMinor'];
            $requiresReview = $limit === null || $amount > $limit;
            $now = now();
            $expenseId = DB::table('hotel_expenses')->insertGetId($facts + [
                'journal_id' => $journalId, 'business_date' => $date,
                'review_status' => $requiresReview ? 'pending' : 'not_required',
                'policy_limit_minor' => $limit,
                'review_reason' => $limit === null ? 'policy_unconfigured' : ($requiresReview ? 'above_limit' : null),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $payoutId = DB::table('cashier_expense_payouts')->insertGetId([
                'hotel_id' => $hotel->id, 'shift_id' => $shiftId,
                'expense_id' => $expenseId, 'amount_minor' => $amount, 'created_at' => $now,
            ]);
            DB::table('cashier_shift_events')->insert([
                'hotel_id' => $hotel->id, 'shift_id' => $shiftId, 'actor_id' => $actor,
                'event' => 'expense_payout', 'details' => json_encode([
                    'expenseId' => (string) $expenseId, 'amountMinor' => $amount,
                    'payee' => $facts['payee'], 'description' => $facts['description'],
                ], JSON_THROW_ON_ERROR), 'created_at' => $now,
            ]);
            return ['expenseId' => (string) $expenseId, 'payoutId' => (string) $payoutId,
                'journalId' => (string) $journalId, 'replayed' => false];
        }, 3);
    }
}

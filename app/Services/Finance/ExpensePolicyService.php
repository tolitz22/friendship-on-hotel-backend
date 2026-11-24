<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ExpensePolicyService
{
    private function authorizeManager(Hotel $hotel, int $actor): void
    {
        abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
            ->where('user_id', $actor)->where('role', 'admin')->where('is_active', true)
            ->exists() && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
    }

    public function policy(Hotel $hotel): array
    {
        $row = DB::table('hotel_expense_policies')->where('hotel_id', $hotel->id)->first();
        return ['configured' => $row !== null,
            'routineLimitMinor' => $row === null ? null : (int) $row->routine_limit_minor];
    }

    public function update(Hotel $hotel, int $actor, array $input): array
    {
        $data = Validator::make($input, [
            'routineLimitMinor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500', 'regex:/\\S/u'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorizeManager($hotel, $actor);
            $previous = DB::table('hotel_expense_policies')->where('hotel_id', $hotel->id)->first();
            $limit = (int) $data['routineLimitMinor'];
            if ($previous !== null && (int) $previous->routine_limit_minor === $limit) {
                return $this->policy($hotel);
            }
            $now = now();
            DB::table('hotel_expense_policies')->updateOrInsert(['hotel_id' => $hotel->id], [
                'routine_limit_minor' => $limit, 'updated_by' => $actor,
                'created_at' => $previous->created_at ?? $now, 'updated_at' => $now,
            ]);
            DB::table('hotel_expense_policy_events')->insert([
                'hotel_id' => $hotel->id, 'actor_id' => $actor,
                'previous_limit_minor' => $previous?->routine_limit_minor,
                'new_limit_minor' => $limit, 'reason' => trim($data['reason']), 'created_at' => $now,
            ]);
            return $this->policy($hotel);
        }, 3);
    }

    public function review(Hotel $hotel, int $actor, int $expenseId, array $input): array
    {
        $data = Validator::make($input, [
            'decision' => ['required', 'in:approve,flag'],
            'note' => ['required', 'string', 'min:5', 'max:500', 'regex:/\\S/u'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $expenseId, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorizeManager($hotel, $actor);
            $expense = DB::table('hotel_expenses')->where('hotel_id', $hotel->id)
                ->where('id', $expenseId)->lockForUpdate()->first();
            abort_unless($expense !== null, 404);
            abort_if((int) $expense->recorded_by === $actor, 403, 'You cannot review your own expense.');
            $existing = DB::table('hotel_expense_reviews')->where('hotel_id', $hotel->id)
                ->where('expense_id', $expenseId)->first();
            if ($existing !== null) {
                abort_unless((int) $existing->reviewed_by === $actor
                    && $existing->decision === $data['decision']
                    && $existing->note === trim($data['note']), 409, 'Expense already reviewed.');
                return ['expenseId' => (string) $expenseId, 'reviewStatus' => $expense->review_status, 'replayed' => true];
            }
            abort_unless($expense->review_status === 'pending', 409, 'Expense is not awaiting review.');
            $status = $data['decision'] === 'approve' ? 'approved' : 'flagged';
            DB::table('hotel_expense_reviews')->insert([
                'hotel_id' => $hotel->id, 'expense_id' => $expenseId, 'reviewed_by' => $actor,
                'decision' => $data['decision'], 'note' => trim($data['note']), 'created_at' => now(),
            ]);
            // A review never deletes an actual cash movement or rewrites its journal.
            DB::table('hotel_expenses')->where('hotel_id', $hotel->id)->where('id', $expenseId)
                ->update(['review_status' => $status, 'updated_at' => now()]);
            return ['expenseId' => (string) $expenseId, 'reviewStatus' => $status, 'replayed' => false];
        }, 3);
    }
}

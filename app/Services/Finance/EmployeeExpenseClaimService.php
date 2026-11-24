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

final class EmployeeExpenseClaimService
{
    public function submit(Hotel $hotel, int $actor, array $input): array
    {
        abort_unless(config('financial_accounting.enable_employee_reimbursements', false), 409, 'Employee reimbursements are not enabled.');
        $data = Validator::make($input, [
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'employeeId' => ['prohibited'],
            'submittedBy' => ['prohibited'],
            'merchant' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'description' => ['required', 'string', 'max:255', 'regex:/\\S/u'],
            'department' => ['required', 'in:rooms,maintenance,administration,utilities,food_beverage'],
            'category' => ['required', 'string', 'max:64', 'regex:/\\S/u'],
            'receiptReference' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'serviceDate' => ['required', 'date_format:Y-m-d'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $membership = HotelMembership::query()->where('hotel_id', $hotel->id)
                ->where('user_id', $actor)->where('is_active', true)->lockForUpdate()->first();
            $user = User::query()->whereKey($actor)->where('is_active', true)->first();
            abort_unless($membership !== null && $user !== null
                && in_array('reimbursement.submit', EnsureHotelPermission::effectiveForMembership($membership), true), 403);
            abort_unless(($hotel->currency ?? 'PHP') === 'PHP', 409, 'Only PHP claims are supported.');
            $merchant = trim(preg_replace('/\\s+/u', ' ', $data['merchant']));
            $receipt = trim(preg_replace('/\\s+/u', ' ', $data['receiptReference']));
            $facts = [
                'hotel_id' => (int) $hotel->id, 'employee_id' => $actor, 'submitted_by' => $actor,
                'request_key' => $data['requestKey'], 'merchant' => $merchant,
                'normalized_merchant' => mb_strtolower($merchant, 'UTF-8'),
                'receipt_reference' => $receipt, 'normalized_receipt_reference' => mb_strtolower($receipt, 'UTF-8'),
                'description' => trim($data['description']), 'department' => $data['department'],
                'category' => trim($data['category']), 'service_date' => $data['serviceDate'],
                'currency' => 'PHP', 'amount_minor' => (int) $data['amountMinor'],
            ];
            $base = DB::table('employee_expense_claims')->where('hotel_id', $hotel->id);
            $existing = (clone $base)->where('request_key', $data['requestKey'])->first();
            if ($existing !== null) {
                foreach ($facts as $key => $value) {
                    abort_unless((string) $existing->$key === (string) $value, 409, 'Claim request key already used with different details.');
                }
                return ['claimId' => (string) $existing->id, 'replayed' => true];
            }
            abort_if((clone $base)->where('normalized_merchant', $facts['normalized_merchant'])
                ->where('normalized_receipt_reference', $facts['normalized_receipt_reference'])->exists(),
                409, 'This merchant receipt already has a reimbursement claim.');
            $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
            abort_if($facts['service_date'] > $today, 422, 'Expense date cannot be in the future.');
            // Submission is not an accounting event. Approval will require an open financial date.
            $id = DB::table('employee_expense_claims')->insertGetId($facts + [
                'employee_name_snapshot' => mb_substr($user->name, 0, 160),
                'created_at' => now(),
            ]);
            return ['claimId' => (string) $id, 'replayed' => false];
        }, 3);
    }
}

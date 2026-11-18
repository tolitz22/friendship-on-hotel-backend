<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Tax policies are hotel-specific, effective-dated, and explicitly approved.
 * No default rate is silently activated; previews never write guest folios.
 */
final class FinancialTaxPolicyService
{
    public function list(int $hotelId): array
    {
        return DB::table('financial_tax_policies')->where('hotel_id', $hotelId)
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->toArray();
    }

    public function draft(int $hotelId, string $classification, string $priceMode,
        int $rateBps, string $from, ?string $until, string $note): int
    {
        try {
            $this->validatePolicy($hotelId, $classification, $priceMode, $rateBps, $from, $until);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['policy' => $exception->getMessage()]);
        }
        if (mb_strlen(trim($note)) < 15 || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['reviewNote' => 'Provide a substantive tax policy review note.']);
        }

        return (int) DB::table('financial_tax_policies')->insertGetId([
            'hotel_id' => $hotelId, 'classification' => $classification,
            'price_mode' => $priceMode, 'rate_bps' => $rateBps,
            'effective_from' => $from, 'effective_until' => $until,
            'status' => 'draft', 'review_note' => trim($note),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function approve(int $hotelId, int $policyId, int $actorId): void
    {
        if ($hotelId < 1 || $policyId < 1 || $actorId < 1) {
            throw new InvalidArgumentException('Invalid tax policy approval identity.');
        }
        DB::transaction(function () use ($hotelId, $policyId, $actorId): void {
            // Use the hotel clock as a shared serialization point for policy approvals.
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)
                ->lockForUpdate()->first();
            if ($clock === null) {
                throw ValidationException::withMessages(['hotel_id' => 'Financial clock must be configured.']);
            }
            $policy = DB::table('financial_tax_policies')->where('hotel_id', $hotelId)
                ->where('id', $policyId)->lockForUpdate()->first();
            if ($policy === null || $policy->status !== 'draft') {
                throw ValidationException::withMessages(['policy_id' => 'Only a draft policy can be approved.']);
            }
            $this->validatePolicy($hotelId, $policy->classification, $policy->price_mode,
                (int) $policy->rate_bps, $policy->effective_from, $policy->effective_until);

            // Do not alter historical tax treatments through retrospective approval.
            if ($policy->effective_from < $clock->business_date) {
                throw ValidationException::withMessages([
                    'effective_from' => 'A new policy cannot begin before the current financial business date.',
                ]);
            }

            $overlaps = DB::table('financial_tax_policies')
                ->where('hotel_id', $hotelId)->where('status', 'approved')
                ->where('id', '!=', $policyId)
                ->where('effective_from', '<=', $policy->effective_until ?? '9999-12-31')
                ->where(function ($query) use ($policy): void {
                    $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $policy->effective_from);
                })->exists();
            if ($overlaps) {
                throw ValidationException::withMessages([
                    'effective_from' => 'An approved tax policy already covers part of this date range.',
                ]);
            }
            DB::table('financial_tax_policies')->where('id', $policyId)
                ->where('hotel_id', $hotelId)->update([
                    'status' => 'approved', 'approved_by' => $actorId,
                    'approved_at' => now(), 'updated_at' => now(),
                ]);
        }, 3);
    }

    public function preview(int $hotelId, string $date, int $amountMinor): array
    {
        if ($hotelId < 1 || !$this->validDate($date)) {
            throw new InvalidArgumentException('Invalid hotel or tax date.');
        }
        $policies = DB::table('financial_tax_policies')
            ->where('hotel_id', $hotelId)->where('status', 'approved')
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            })->get();
        if ($policies->count() !== 1) {
            throw ValidationException::withMessages([
                'tax_policy' => 'Exactly one approved effective tax policy is required.',
            ]);
        }
        $policy = $policies->first();
        return [
            'policy_id' => (string) $policy->id,
            'effective_date' => $date,
            'preview_only' => true,
            'folio_updated' => false,
            'journal_posted' => false,
            'breakdown' => (new PhilippineTaxCalculator())->calculate(
                $amountMinor, $policy->classification, $policy->price_mode, (int) $policy->rate_bps
            ),
        ];
    }

    private function validatePolicy(int $hotelId, string $classification,
        string $priceMode, int $rateBps, string $from, ?string $until): void
    {
        if ($hotelId < 1 || !$this->validDate($from)
            || ($until !== null && (!$this->validDate($until) || $until < $from))) {
            throw new InvalidArgumentException('Invalid hotel or effective-date range.');
        }
        (new PhilippineTaxCalculator())->calculate(0, $classification, $priceMode, $rateBps);
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeasonalRateService
{
    public function rules(Hotel $hotel, int $planId)
    {
        return DB::table('room_rate_rules')->where('hotel_id', $hotel->id)
            ->where('rate_plan_id', $planId);
    }

    public function create(Hotel $hotel, int $actor, int $planId, array $input): int
    {
        return DB::transaction(function () use ($hotel, $actor, $planId, $input): int {
            $plan = DB::table('room_rate_plans')->where('hotel_id', $hotel->id)
                ->where('id', $planId)->first();
            abort_unless($plan !== null, 404);
            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $plan->room_type_id)->lockForUpdate()->firstOrFail();
            $overlap = $this->rules($hotel, $planId)->whereNull('retired_at')->where('kind', $input['kind']);
            if (in_array($input['kind'], ['corporate', 'promotion'], true)) {
                $overlap->where('eligibility_code', $input['eligibilityCode']);
            }
            if ($overlap
                ->where('starts_on', '<=', $input['endsOn'])
                ->where('ends_on', '>=', $input['startsOn'])->exists()) {
                throw ValidationException::withMessages(['startsOn' => ['A rule of this kind already overlaps these dates.']]);
            }
            return (int) DB::table('room_rate_rules')->insertGetId([
                'hotel_id' => $hotel->id, 'rate_plan_id' => $planId,
                'name' => $input['name'], 'kind' => $input['kind'],
                'starts_on' => $input['startsOn'], 'ends_on' => $input['endsOn'],
                'nightly_minor' => $input['nightlyMinor'], 'created_by' => $actor,
                'eligibility_code' => $input['eligibilityCode'] ?? null,
                'minimum_nights' => $input['minimumNights'] ?? null,
                'weekend_days' => $input['kind'] === 'weekend' ? json_encode($input['weekendDays'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function replace(Hotel $hotel, int $actor, int $planId, int $ruleId, ?array $input): ?int
    {
        return DB::transaction(function () use ($hotel, $actor, $planId, $ruleId, $input): ?int {
            $plan = DB::table('room_rate_plans')->where('hotel_id', $hotel->id)->where('id', $planId)->first();
            abort_unless($plan !== null, 404);
            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $plan->room_type_id)->lockForUpdate()->firstOrFail();
            $rule = $this->rules($hotel, $planId)->where('id', $ruleId)->lockForUpdate()->first();
            abort_unless($rule !== null, 404);
            abort_if($rule->retired_at !== null, 409, 'This rule was already retired. Reload the list.');
            $this->rules($hotel, $planId)->where('id', $ruleId)->update([
                'retired_at' => now(), 'retired_by' => $actor, 'updated_at' => now(),
            ]);
            if ($input === null) return null;
            $newId = $this->create($hotel, $actor, $planId, $input);
            $this->rules($hotel, $planId)->where('id', $newId)->update(['replaces_rule_id' => $ruleId]);
            return $newId;
        }, 3);
    }

    public function preview(Hotel $hotel, int $typeId, int $planId, StayPeriod $stay, ?string $corporateCode = null, ?string $promoCode = null): array
    {
        $base = app(RoomRateQuoteService::class)->quote($hotel, $typeId, $planId, $stay);
        $rules = $this->rules($hotel, $planId)->whereNull('retired_at')->where('starts_on', '<', $stay->departure)
            ->where('ends_on', '>=', $stay->arrival)->get();
        $selectedKind = $corporateCode !== null ? 'corporate' : ($promoCode !== null ? 'promotion' : null);
        $selectedCode = $corporateCode ?? $promoCode;
        $eligible = $rules->filter(fn ($rule) => $rule->kind === $selectedKind
            && $rule->eligibility_code === $selectedCode
            && $stay->nights() >= (int) $rule->minimum_nights
            && $rule->starts_on <= $stay->arrival && $rule->ends_on >= $base['nights'][count($base['nights']) - 1])->first();
        if ($selectedKind !== null && $eligible === null) {
            throw ValidationException::withMessages(['eligibilityCode' => ['No eligible rate covers the entire stay and minimum nights.']]);
        }
        $total = 0;
        $nightlyRates = [];
        foreach ($base['nights'] as $date) {
            $matches = $rules->filter(fn ($rule) => $rule->starts_on <= $date && $rule->ends_on >= $date);
            $weekday = (int) (new \DateTimeImmutable($date))->format('N');
            $weekend = $matches->first(fn ($rule) => $rule->kind === 'weekend'
                && in_array($weekday, json_decode($rule->weekend_days ?? '[]', true, 512, JSON_THROW_ON_ERROR), true));
            $rule = $eligible ?? $matches->firstWhere('kind', 'date_override')
                ?? $matches->firstWhere('kind', 'holiday')
                ?? $matches->firstWhere('kind', 'seasonal')
                ?? $weekend;
            $amount = $rule === null ? $base['nightlyMinor'] : (int) $rule->nightly_minor;
            if ($amount < 0 || $amount > PHP_INT_MAX - $total) {
                throw ValidationException::withMessages(['nightlyMinor' => ['Quote exceeds accounting limits.']]);
            }
            $total += $amount;
            $nightlyRates[] = ['date' => $date, 'amountMinor' => $amount,
                'source' => $rule?->kind ?? 'base', 'ruleId' => $rule === null ? null : (string) $rule->id];
        }
        return array_replace($base, ['nightlyRates' => $nightlyRates, 'roomSubtotalMinor' => $total,
            'previewOnly' => true, 'appliedToBookings' => false]);
    }
}

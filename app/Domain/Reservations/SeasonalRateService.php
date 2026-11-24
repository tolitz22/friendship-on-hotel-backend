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
            if ($this->rules($hotel, $planId)->where('kind', $input['kind'])
                ->where('starts_on', '<=', $input['endsOn'])
                ->where('ends_on', '>=', $input['startsOn'])->exists()) {
                throw ValidationException::withMessages(['startsOn' => ['A rule of this kind already overlaps these dates.']]);
            }
            return (int) DB::table('room_rate_rules')->insertGetId([
                'hotel_id' => $hotel->id, 'rate_plan_id' => $planId,
                'name' => $input['name'], 'kind' => $input['kind'],
                'starts_on' => $input['startsOn'], 'ends_on' => $input['endsOn'],
                'nightly_minor' => $input['nightlyMinor'], 'created_by' => $actor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function preview(Hotel $hotel, int $typeId, int $planId, StayPeriod $stay): array
    {
        $base = app(RoomRateQuoteService::class)->quote($hotel, $typeId, $planId, $stay);
        $rules = $this->rules($hotel, $planId)->where('starts_on', '<', $stay->departure)
            ->where('ends_on', '>=', $stay->arrival)->get();
        $total = 0;
        $nightlyRates = [];
        foreach ($base['nights'] as $date) {
            $matches = $rules->filter(fn ($rule) => $rule->starts_on <= $date && $rule->ends_on >= $date);
            $rule = $matches->firstWhere('kind', 'date_override') ?? $matches->firstWhere('kind', 'seasonal');
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

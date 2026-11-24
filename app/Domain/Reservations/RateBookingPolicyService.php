<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RateBookingPolicyService
{
    public function plan(Hotel $hotel, int $planId): object
    {
        $plan = DB::table('room_rate_plans')->where('hotel_id', $hotel->id)->where('id', $planId)->first();
        abort_unless($plan !== null, 404);
        return $plan;
    }

    public function versions(Hotel $hotel, int $planId)
    {
        return DB::table('rate_booking_policies')->where('hotel_id', $hotel->id)->where('rate_plan_id', $planId);
    }

    public function save(Hotel $hotel, int $actor, int $planId, array $input): object
    {
        return DB::transaction(function () use ($hotel, $actor, $planId, $input): object {
            $plan = $this->plan($hotel, $planId);
            DB::table('room_types')->where('hotel_id', $hotel->id)->where('id', $plan->room_type_id)->lockForUpdate()->firstOrFail();
            $current = (int) $this->versions($hotel, $planId)->max('version');
            abort_if($current !== (int) $input['expectedVersion'], 409, 'Policy changed. Reload before saving.');
            $id = DB::table('rate_booking_policies')->insertGetId([
                'hotel_id' => $hotel->id, 'rate_plan_id' => $planId, 'version' => $current + 1,
                'deposit_kind' => $input['depositKind'], 'deposit_value' => $input['depositValue'],
                'free_cancel_days' => $input['freeCancelDays'],
                'late_cancel_kind' => $input['lateCancelKind'], 'late_cancel_value' => $input['lateCancelValue'],
                'no_show_kind' => $input['noShowKind'], 'no_show_value' => $input['noShowValue'],
                'reason' => trim($input['reason']), 'created_by' => $actor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->versions($hotel, $planId)->where('id', $id)->first();
        }, 3);
    }

    private function amount(string $kind, int $value, int $subtotal, int $firstNight): int
    {
        return match ($kind) {
            'none' => 0,
            'fixed' => min($subtotal, $value),
            'first_night' => $firstNight,
            'full' => $subtotal,
            'percent' => intdiv($subtotal, 10000) * $value + intdiv(($subtotal % 10000) * $value + 5000, 10000),
        };
    }

    public function preview(Hotel $hotel, int $planId, array $input): array
    {
        $plan = $this->plan($hotel, $planId);
        $policy = $this->versions($hotel, $planId)->orderByDesc('version')->first();
        if ($policy === null) {
            throw ValidationException::withMessages(['policy' => ['Configure a policy version first.']]);
        }
        $stay = new StayPeriod($input['checkIn'], $input['checkOut']);
        $quote = app(SeasonalRateService::class)->preview($hotel, (int) $plan->room_type_id, $planId, $stay,
            isset($input['corporateCode']) ? strtoupper($input['corporateCode']) : null,
            isset($input['promoCode']) ? strtoupper($input['promoCode']) : null);
        $subtotal = $quote['roomSubtotalMinor'];
        $firstNight = $quote['nightlyRates'][0]['amountMinor'];
        $cutoff = (new \DateTimeImmutable($stay->arrival, new \DateTimeZone($hotel->timezone ?: 'Asia/Manila')))
            ->modify('-'.(int) $policy->free_cancel_days.' days')->format('Y-m-d');
        $free = $input['cancellationDate'] <= $cutoff && $input['cancellationDate'] < $stay->arrival;
        $deposit = $this->amount($policy->deposit_kind, (int) $policy->deposit_value, $subtotal, $firstNight);
        $late = $this->amount($policy->late_cancel_kind, (int) $policy->late_cancel_value, $subtotal, $firstNight);
        $noShow = $this->amount($policy->no_show_kind, (int) $policy->no_show_value, $subtotal, $firstNight);
        return [
            'policyVersion' => (int) $policy->version, 'currency' => $quote['currency'],
            'roomSubtotalMinor' => $subtotal, 'depositRequiredMinor' => $deposit,
            'remainingRoomSubtotalMinor' => $subtotal - $deposit,
            'freeCancellationThrough' => min($cutoff, (new \DateTimeImmutable($stay->arrival))->modify('-1 day')->format('Y-m-d')),
            'cancellationFeeMinor' => $free ? 0 : $late, 'noShowFeeMinor' => $noShow,
            'freeCancellation' => $free, 'previewOnly' => true, 'appliedToBookings' => false,
        ];
    }
}

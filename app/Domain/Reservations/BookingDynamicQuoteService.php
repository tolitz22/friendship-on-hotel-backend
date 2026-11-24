<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

final class BookingDynamicQuoteService
{
    public function quote(Hotel $hotel, int $typeId, int $planId, StayPeriod $stay, ?string $corporateCode = null, ?string $promoCode = null): array
    {
        abort_if($corporateCode !== null && $promoCode !== null, 422, 'Choose one eligibility code.');
        return DB::transaction(function () use ($hotel, $typeId, $planId, $stay, $corporateCode, $promoCode): array {
            DB::table('room_types')->where('hotel_id', $hotel->id)->where('id', $typeId)->lockForUpdate()->firstOrFail();
            $quote = app(SeasonalRateService::class)->preview($hotel, $typeId, $planId, $stay,
                $corporateCode === null ? null : strtoupper($corporateCode), $promoCode === null ? null : strtoupper($promoCode));
            $policy = DB::table('rate_booking_policies')->where('hotel_id', $hotel->id)->where('rate_plan_id', $planId)->orderByDesc('version')->first();
            $terms = $policy === null ? null : [
                'version' => (int) $policy->version,
                'depositKind' => $policy->deposit_kind, 'depositValue' => (int) $policy->deposit_value,
                'freeCancelDays' => (int) $policy->free_cancel_days,
                'lateCancelKind' => $policy->late_cancel_kind, 'lateCancelValue' => (int) $policy->late_cancel_value,
                'noShowKind' => $policy->no_show_kind, 'noShowValue' => (int) $policy->no_show_value,
            ];
            $data = array_replace($quote, ['hotelId' => (string) $hotel->id, 'checkIn' => $stay->arrival,
                'checkOut' => $stay->departure, 'policyTerms' => $terms,
                'corporateCode' => $corporateCode === null ? null : strtoupper($corporateCode),
                'promoCode' => $promoCode === null ? null : strtoupper($promoCode)]);
            $data['quoteToken'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            return $data;
        }, 3);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fixed, nightly, room-type rate quotation in integer minor currency units.
 * No taxes/discounts/deposits are inferred; quote is not a payment demand.
 */
final class RoomRateQuoteService
{
    public function quote(Hotel $hotel, int $typeId, int $ratePlanId, StayPeriod $stay): array
    {
        if ($stay->nights() > (int) config('booking.maximum_nights', 31)) {
            throw new HttpException(422, 'Stay exceeds supported nights.');
        }

        $rate = DB::table('room_rate_plans')->where('hotel_id', $hotel->id)
            ->where('room_type_id', $typeId)->where('id', $ratePlanId)
            ->where('is_active', true)->first();
        abort_unless($rate !== null, 404);

        $currency = strtoupper((string) ($hotel->currency ?: 'PHP'));
        if ($rate->currency !== $currency) {
            throw new HttpException(409, 'Rate currency differs from the hotel currency.');
        }

        $price = (int) $rate->nightly_minor;
        // Protect multiplication and downstream storage from overflowing signed BIGINT.
        if ($price < 0 || $price > intdiv(PHP_INT_MAX, $stay->nights())) {
            throw new HttpException(422, 'Quote exceeds supported accounting limits.');
        }

        $dates = [];
        $start = new \DateTimeImmutable($stay->arrival);
        for ($i = 0; $i < $stay->nights(); $i++) {
            $dates[] = $start->modify('+'.$i.' days')->format('Y-m-d');
        }

        return [
            'ratePlanId' => (string) $rate->id,
            'roomTypeId' => (string) $typeId,
            'currency' => $currency,
            'nightlyMinor' => $price,
            'nights' => $dates,
            'roomSubtotalMinor' => $price * $stay->nights(),
            'taxesCalculated' => false,
            'feesCalculated' => false,
            'depositCalculated' => false,
            'isFinalPayableTotal' => false,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class NoShowWorkflow
{
    public static function enabled(int $hotelId): bool
    {
        $hotels = array_map('trim', explode(',', (string) config('booking.no_show_adjustments_hotel_ids', '')));

        return config('booking.no_show_adjustments_enabled', false)
            && in_array((string) $hotelId, $hotels, true);
    }

    public static function eligibleAt(Hotel $hotel, string $arrival): CarbonImmutable
    {
        $cutoff = (string) config('booking.no_show_cutoff_next_day', '06:00');
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $cutoff)) {
            $cutoff = '06:00';
        }
        [$hour, $minute] = array_map('intval', explode(':', $cutoff));

        return CarbonImmutable::parse($arrival, $hotel->timezone ?: 'Asia/Manila')
            ->addDay()->setTime($hour, $minute);
    }

    public static function lockClockForNoShow(Hotel $hotel, int $bookingId): void
    {
        if (DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('id', $bookingId)->where('lifecycle_state', 'no_show')->exists()) {
            DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
        }
    }

    public static function accountingBlocker(int $hotelId, int $folioId, bool $markingNoShow = false): ?string
    {
        foreach ([
            'folio_settlement_signoffs' => 'This folio has an immutable settlement sign-off.',
            'financial_folio_night_assessments' => 'Room-night accounting already exists for this folio.',
            'folio_settlement_allocations' => 'Payments have already been allocated to this folio.',
            'folio_refund_requests' => 'Refund requests already exist for this folio.',
            'folio_adjustments' => 'This folio has existing financial adjustments.',
        ] as $table => $message) {
            if (DB::table($table)->where('hotel_id', $hotelId)->where('folio_id', $folioId)->exists()) {
                if ($table === 'financial_folio_night_assessments' && $markingNoShow
                    && self::enabled($hotelId) && config('financial_accounting.enable_no_show_room_correction_review', false)) {
                    continue;
                }
                if ($table === 'financial_folio_night_assessments'
                    && config('financial_accounting.enable_no_show_room_correction_posting', false)
                    && app(\App\Services\Finance\NoShowRoomCorrectionCompletion::class)->valid($hotelId, $folioId)) {
                    continue;
                }
                return $message;
            }
        }

        return null;
    }
}

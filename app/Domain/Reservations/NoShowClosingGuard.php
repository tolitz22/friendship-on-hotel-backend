<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** No-show folios require dedicated accounting, not occupied-night assessment. */
final class NoShowClosingGuard
{
    public static function unresolved(int $hotelId, string $businessDate): int
    {
        foreach (['reservation_records', 'reservation_folios'] as $table) {
            if (!Schema::hasTable($table)) return 0;
        }

        // Until a dedicated fee journal and reviewed clearance exist, even a
        // provisional folio adjustment is not sufficient to clear this guard.
        return DB::table('reservation_records as r')
            ->join('reservation_folios as f', function ($join): void {
                $join->on('f.reservation_record_id', '=', 'r.id')
                    ->on('f.hotel_id', '=', 'r.hotel_id');
            })
            ->where('r.hotel_id', $hotelId)
            ->where('r.lifecycle_state', 'no_show')
            ->where('r.check_in', '<=', $businessDate)
            ->distinct()->count('r.id');
    }
}

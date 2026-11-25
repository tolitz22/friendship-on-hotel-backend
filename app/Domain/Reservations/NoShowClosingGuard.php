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

        $query = DB::table('reservation_records as r')
            ->join('reservation_folios as f', function ($join): void {
                $join->on('f.reservation_record_id', '=', 'r.id')
                    ->on('f.hotel_id', '=', 'r.hotel_id');
            })
            ->where('r.hotel_id', $hotelId)
            ->where('r.lifecycle_state', 'no_show')
            ->where('r.check_in', '<=', $businessDate);
        if (Schema::hasTable('no_show_financial_clearances') && Schema::hasTable('no_show_financial_reviews')
            && Schema::hasTable('financial_posting_journals')) {
            $query->whereNotExists(function ($sub) use ($businessDate): void {
                $sub->selectRaw('1')->from('no_show_financial_reviews as v')
                    ->join('no_show_financial_clearances as c', function ($join): void {
                        $join->on('c.review_id', '=', 'v.id')->on('c.hotel_id', '=', 'v.hotel_id');
                    })
                    ->whereColumn('v.hotel_id', 'r.hotel_id')
                    ->whereColumn('v.reservation_record_id', 'r.id')->whereColumn('v.folio_id', 'f.id')
                    ->whereColumn('c.gross_minor', 'v.fee_minor')
                    ->whereColumn('c.reviewed_by', '!=', 'v.actor_id')
                    ->where('c.business_date', '<=', $businessDate)
                    ->whereRaw('c.vat_minor + c.revenue_minor = c.gross_minor')
                    ->where(function ($valid): void {
                        $valid->where(function ($zero): void {
                            $zero->where('c.gross_minor', 0)->whereNull('c.journal_id');
                        })->orWhere(function ($posted): void {
                            $posted->where('c.gross_minor', '>', 0)->whereExists(function ($journal): void {
                                $journal->selectRaw('1')->from('financial_posting_journals as j')
                                    ->whereColumn('j.id', 'c.journal_id')->whereColumn('j.hotel_id', 'c.hotel_id')
                                    ->whereColumn('j.business_date', 'c.business_date')
                                    ->whereColumn('j.posted_by', 'c.reviewed_by')->where('j.source_type', 'no_show_fee')
                                    ->whereNotExists(function ($reversal): void {
                                        $reversal->selectRaw('1')->from('financial_posting_journals as x')
                                            ->whereColumn('x.reverses_journal_id', 'j.id');
                                    });
                            });
                        });
                    });
            });
        }
        return $query->distinct()->count('r.id');
    }
}

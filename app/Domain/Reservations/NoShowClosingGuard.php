<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Services\Finance\NoShowEffectiveFeeClearance;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** No-show folios require dedicated accounting, not occupied-night assessment. */
final class NoShowClosingGuard
{
    public static function unresolved(int $hotelId, string $businessDate): int
    {
        return self::query($hotelId, $businessDate)?->count('r.id') ?? 0;
    }

    public static function bookings(int $hotelId, string $businessDate): array
    {
        return self::query($hotelId, $businessDate)?->orderBy('r.check_in')->orderBy('r.id')->limit(30)
            ->get(['r.id', 'r.reference', 'r.check_in'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'reference' => $row->reference, 'arrival' => $row->check_in])
            ->all() ?? [];
    }

    private static function query(int $hotelId, string $businessDate): ?Builder
    {
        foreach (['reservation_records', 'reservation_folios'] as $table) {
            if (!Schema::hasTable($table)) {
                return null;
            }
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
            && Schema::hasColumn('financial_posting_journals', 'reverses_journal_id')
            && Schema::hasTable('financial_posting_lines')) {
            $query->whereNotExists(function ($sub) use ($businessDate): void {
                $sub->selectRaw('1')->from('no_show_financial_reviews as v')
                    ->join('no_show_financial_clearances as c', function ($join): void {
                        $join->on('c.review_id', '=', 'v.id')->on('c.hotel_id', '=', 'v.hotel_id');
                    })
                    ->whereColumn('v.hotel_id', 'r.hotel_id')
                    ->whereColumn('v.reservation_record_id', 'r.id')->whereColumn('v.folio_id', 'f.id')
                    ->where('c.business_date', '<=', $businessDate);
                self::requireValidClearance($sub);
            });
        }
        // Corrected fees have their own immutable validation path. Evaluate all
        // applied corrections, even if an unrelated original clearance is valid.
        if (Schema::hasTable('no_show_fee_correction_proposals')
            && Schema::hasTable('financial_posting_journals')) {
            $corrections = app(NoShowEffectiveFeeClearance::class);
            $candidates = DB::table('reservation_records as r')
                ->join('reservation_folios as f', function ($join): void {
                    $join->on('f.reservation_record_id', '=', 'r.id')->on('f.hotel_id', '=', 'r.hotel_id');
                })
                ->join('no_show_fee_correction_proposals as p', function ($join): void {
                    $join->on('p.booking_id', '=', 'r.id')->on('p.hotel_id', '=', 'r.hotel_id');
                })
                ->where('r.hotel_id', $hotelId)->where('r.lifecycle_state', 'no_show')
                ->where('r.check_in', '<=', $businessDate)
                ->distinct()->get(['r.id as booking_id', 'f.id as folio_id']);
            $valid = [];
            $invalid = [];
            foreach ($candidates as $candidate) {
                $proposal = $corrections->appliedProposal($hotelId, (int) $candidate->booking_id);
                if ($proposal === null) continue;
                $clearance = $corrections->resolve($hotelId, (int) $candidate->booking_id, (int) $candidate->folio_id, $proposal);
                if ($clearance !== null && (string) $clearance->business_date <= $businessDate) {
                    $valid[] = (int) $candidate->booking_id;
                } else {
                    $invalid[] = (int) $candidate->booking_id;
                }
            }
            $query->whereNotIn('r.id', $valid);
            if ($invalid !== []) {
                // Group the original predicate before OR-ing invalid corrections;
                // IDs originate only from the same scoped hotel/date query.
                $query->orWhere(function (Builder $failed) use ($hotelId, $businessDate, $invalid): void {
                    $failed->where('r.hotel_id', $hotelId)->where('r.lifecycle_state', 'no_show')
                        ->where('r.check_in', '<=', $businessDate)->whereIn('r.id', $invalid);
                });
            }
        }
        return $query;
    }

    public static function requireValidClearance(Builder $query): void
    {
        $sourceReference = DB::connection()->getDriverName() === 'sqlite'
            ? "('no-show-review-' || v.id)"
            : "CONCAT('no-show-review-', v.id)";

        $query->whereColumn('c.gross_minor', 'v.fee_minor')
            ->whereColumn('c.reviewed_by', '!=', 'v.actor_id')
            ->whereRaw('c.vat_minor + c.revenue_minor = c.gross_minor')
            ->where(function (Builder $valid) use ($sourceReference): void {
                $valid->where(fn (Builder $zero) => $zero->where('c.gross_minor', 0)->whereNull('c.journal_id'))
                    ->orWhere(function (Builder $posted) use ($sourceReference): void {
                        $posted->where('c.gross_minor', '>', 0)->whereExists(function (Builder $journal) use ($sourceReference): void {
                            $journal->selectRaw('1')->from('financial_posting_journals as j')
                                ->whereColumn('j.id', 'c.journal_id')->whereColumn('j.hotel_id', 'c.hotel_id')
                                ->whereColumn('j.business_date', 'c.business_date')->whereColumn('j.posted_by', 'c.reviewed_by')
                                ->where('j.source_type', 'no_show_fee')->whereRaw("j.source_reference = $sourceReference")
                                ->whereNotExists(fn (Builder $reversal) => $reversal->selectRaw('1')
                                    ->from('financial_posting_journals as x')->whereColumn('x.hotel_id', 'j.hotel_id')
                                    ->whereColumn('x.reverses_journal_id', 'j.id'))
                                ->whereRaw('c.gross_minor = (select coalesce(sum(l.debit_minor), 0) from financial_posting_lines l where l.journal_id = j.id)')
                                ->whereRaw('c.gross_minor = (select coalesce(sum(l.credit_minor), 0) from financial_posting_lines l where l.journal_id = j.id)')
                                ->whereRaw("c.gross_minor = (select coalesce(sum(l.debit_minor), 0) from financial_posting_lines l where l.journal_id = j.id and l.account_code = 'AR')")
                                ->whereRaw("c.revenue_minor = (select coalesce(sum(l.credit_minor), 0) from financial_posting_lines l where l.journal_id = j.id and l.account_code = 'NO_SHOW_REV')")
                                ->whereRaw("c.vat_minor = (select coalesce(sum(l.credit_minor), 0) from financial_posting_lines l where l.journal_id = j.id and l.account_code = 'VAT_PAYABLE')");
                        });
                    });
            });
    }
}

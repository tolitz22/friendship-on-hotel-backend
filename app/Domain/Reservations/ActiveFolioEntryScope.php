<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/** Shared filter for operational and accounting reads of active folio entries. */
final class ActiveFolioEntryScope
{
    public static function apply(Builder $query, string $alias = 'folio_entries'): Builder
    {
        if (!Schema::hasTable('reservation_pricing_revisions')) {
            return $query;
        }

        return $query->whereNotExists(function (Builder $sub) use ($alias): void {
            $sub->selectRaw('1')->from('reservation_pricing_revision_nights as rn')
                ->join('reservation_pricing_revisions as pr', 'pr.id', '=', 'rn.revision_id')
                ->whereColumn('pr.hotel_id', $alias.'.hotel_id')
                ->whereColumn('pr.folio_id', $alias.'.folio_id')
                ->whereColumn('rn.original_entry_id', $alias.'.id')
                ->where('rn.kind', 'reversal');
        });
    }
}

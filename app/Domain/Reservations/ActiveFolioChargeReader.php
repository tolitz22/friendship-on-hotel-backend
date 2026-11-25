<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read effective folio amounts without rewriting original charge history. */
final class ActiveFolioChargeReader
{
    public function amounts(object $folio): Collection
    {
        $base = DB::table('folio_entries')->where('hotel_id', $folio->hotel_id)
            ->where('folio_id', $folio->id);

        if (!Schema::hasTable('reservation_pricing_revisions')) {
            return $base->get(['amount_minor']);
        }

        $revision = DB::table('reservation_pricing_revisions')
            ->where('hotel_id', $folio->hotel_id)->where('folio_id', $folio->id)
            ->where('reservation_record_id', $folio->reservation_record_id)
            ->orderByDesc('id')->first();
        if ($revision === null) {
            return $base->get(['amount_minor']);
        }

        $nights = DB::table('reservation_pricing_revision_nights')
            ->where('revision_id', $revision->id);
        $reversed = (clone $nights)->where('kind', 'reversal')
            ->whereNotNull('original_entry_id')->pluck('original_entry_id');
        $replacement = (clone $nights)->where('kind', 'replacement')
            ->get(['amount_minor']);
        $originalRows = (clone $base)->where('entry_type', 'room_night')
            ->whereIn('id', $reversed)->get(['amount_minor']);
        if ($reversed->isEmpty() || $originalRows->count() !== $reversed->count()
            || $replacement->isEmpty()
            || (int) $originalRows->sum('amount_minor') !== (int) $revision->original_subtotal_minor
            || (int) $replacement->sum('amount_minor') !== (int) $revision->replacement_subtotal_minor) {
            throw new HttpException(409, 'Pricing revision records require financial review.');
        }

        if (!Schema::hasColumn('reservation_pricing_revision_nights', 'replacement_entry_id')) {
            return $base->whereNotIn('id', $reversed)->get(['amount_minor'])->concat($replacement);
        }

        // Materialized replacement nights already appear in the base ledger.
        // Validate their identity and amount before counting each charge once.
        $details = (clone $nights)->where('kind', 'replacement')->get();
        $materialized = $details->whereNotNull('replacement_entry_id');
        $ids = $materialized->pluck('replacement_entry_id');
        $canonical = (clone $base)->where('entry_type', 'room_night')
            ->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($materialized as $detail) {
            $entry = $canonical->get($detail->replacement_entry_id);
            if ($entry === null || $reversed->contains($entry->id)
                || $entry->stay_date !== $detail->stay_date
                || (int) $entry->rate_plan_id !== (int) $detail->rate_plan_id
                || (int) $entry->amount_minor !== (int) $detail->amount_minor) {
                throw new HttpException(409, 'Replacement accounting entry does not match its revision.');
            }
        }

        return $base->whereNotIn('id', $reversed)->get(['amount_minor'])
            ->concat($details->whereNull('replacement_entry_id')->map(
                fn ($detail) => (object) ['amount_minor' => $detail->amount_minor]
            )->values());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NoShowFinancialReviewService
{
    /** Read-only proposal. Never posts a fee, waives a charge or refunds money. */
    public function preview(Hotel $hotel, int $bookingId): array
    {
        return DB::transaction(function () use ($hotel, $bookingId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking !== null, 404);
            abort_unless($booking->lifecycle_state === 'no_show', 409, 'Record the no-show before reviewing its finances.');

            $accounting = app(FolioAccountingService::class);
            $folio = $accounting->findFolio($hotel, $bookingId, true);
            $summary = $accounting->summaryFor($folio);
            $snapshot = DB::table('booking_pricing_snapshots')->where('hotel_id', $hotel->id)
                ->where('reservation_record_id', $bookingId)->first();
            $terms = $snapshot === null ? null : (json_decode($snapshot->snapshot, true, 512, JSON_THROW_ON_ERROR)['policyTerms'] ?? null);
            $nights = ActiveFolioEntryScope::apply(DB::table('folio_entries'))
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)
                ->where('entry_type', 'room_night')->orderBy('stay_date')->get();
            $subtotal = (int) $nights->sum('amount_minor');
            $firstNight = (int) ($nights->first()->amount_minor ?? 0);
            $kind = $terms['noShowKind'] ?? null;
            $value = $terms['noShowValue'] ?? null;
            $blockers = [];
            $fee = null;
            if (!is_int($value) || $value < 0 || $subtotal < 0 || $nights->isEmpty()
                || ($kind === 'percent' && $value > 10000)) {
                $blockers[] = 'No valid recorded no-show policy. Manual policy review required.';
            } else {
                $fee = match ($kind) {
                    'none' => 0,
                    'fixed' => min($subtotal, $value),
                    'first_night' => $firstNight,
                    'full' => $subtotal,
                    'percent' => intdiv($subtotal, 10000) * $value + intdiv(($subtotal % 10000) * $value + 5000, 10000),
                    default => null,
                };
                if ($fee === null) {
                    $blockers[] = 'Unsupported recorded no-show policy.';
                }
            }
            if (Schema::hasTable('reservation_pricing_revisions') && DB::table('reservation_pricing_revisions')
                ->where('hotel_id', $hotel->id)->where('folio_id', $folio->id)->exists()) {
                $fee = null;
                $blockers[] = 'Revised stay policy requires manual review.';
            }
            foreach (['financial_folio_night_assessments', 'folio_settlement_signoffs', 'folio_settlement_allocations', 'folio_refund_requests'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->where('hotel_id', $hotel->id)
                    ->where('folio_id', $folio->id)->exists()) {
                    $blockers[] = 'Existing accounting or settlement activity requires reviewed financial correction.';
                    break;
                }
            }
            return [
                'bookingId' => (string) $bookingId, 'currency' => $folio->currency,
                'policyVersion' => $terms['version'] ?? null, 'noShowKind' => $kind,
                'originalRoomSubtotalMinor' => $subtotal, 'proposedNoShowFeeMinor' => $fee,
                'proposedRoomCreditMinor' => $fee === null ? null : $subtotal - $fee,
                'currentFolioSummary' => $summary, 'blockers' => $blockers,
                'previewOnly' => true, 'applied' => false,
            ];
        }, 3);
    }
}

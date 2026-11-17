<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/**
 * Phase 57: read-only NIGHT AUDIT PREFLIGHT, NOT financial day closing.
 *
 * Booking creation already preposts one room-night entry for every planned
 * night. Never post a second nightly room charge here.
 * Staff-confirmed payments are evidence, not gateway settlement.
 */
final class NightAuditPreviewService
{
    public function preview(Hotel $hotel, string $date): array
    {
        $currency = strtoupper((string) ($hotel->currency ?: 'PHP'));
        $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $roomCount = DB::table('rooms')->where('hotel_id', $hotel->id)->count();

        $eligible = DB::table('reservation_records as r')
            ->where('r.hotel_id', $hotel->id)
            ->where('r.check_in', '<=', $date)
            ->where('r.check_out', '>', $date)
            ->whereNotIn('r.lifecycle_state', ['cancelled', 'no_show'])
            ->get(['r.id', 'r.reference', 'r.lifecycle_state', 'r.assigned_room_id']);

        $folioRows = DB::table('reservation_folios as f')
            ->join('reservation_records as r', function ($join): void {
                $join->on('r.id', '=', 'f.reservation_record_id')
                    ->on('r.hotel_id', '=', 'f.hotel_id');
            })
            ->where('f.hotel_id', $hotel->id)
            ->where('r.check_in', '<=', $date)->where('r.check_out', '>', $date)
            ->whereNotIn('r.lifecycle_state', ['cancelled', 'no_show'])
            ->get(['r.id as booking_id','r.reference','f.id as folio_id','f.currency']);

        $folios = $folioRows->keyBy('booking_id');
        $folioIds = $folioRows->pluck('folio_id')->all();
        $roomEntries = $folioIds === [] ? collect()
            : DB::table('folio_entries')
                ->where('hotel_id', $hotel->id)->whereIn('folio_id', $folioIds)
                ->where('entry_type','room_night')->where('stay_date', $date)
                ->get(['id','folio_id','amount_minor'])->groupBy('folio_id');

        $missing = [];
        $duplicate = [];
        $unpriced = [];
        $postedMinor = 0;
        foreach ($eligible as $booking) {
            $folio = $folios->get($booking->id);
            if ($folio === null) {
                $unpriced[] = ['bookingId' => (string) $booking->id, 'reference' => $booking->reference];
                continue;
            }
            $entries = $roomEntries->get($folio->folio_id, collect());
            if ($entries->count() !== 1 || $folio->currency !== $currency) {
                $issue = [
                    'bookingId' => (string) $booking->id,
                    'reference' => $booking->reference,
                    'reason' => $folio->currency !== $currency ? 'currency_mismatch'
                        : ($entries->count() > 1 ? 'duplicate_night' : 'missing_night'),
                ];
                if ($entries->count() > 1) {
                    $duplicate[] = $issue;
                } else {
                    $missing[] = $issue;
                }
                continue;
            }
            $postedMinor += (int) $entries->first()->amount_minor;
        }

        $openShifts = DB::table('cashier_shifts')->where('hotel_id',$hotel->id)
            ->where('status','open')->count();
        $pendingPayments = DB::table('folio_payment_records')->where('hotel_id',$hotel->id)
            ->where('status','pending_review')->count();
        $unreconciledShifts = DB::table('cashier_shifts')->where('hotel_id',$hotel->id)
            ->whereIn('status',['submitted','flagged'])->count();

        $problems = [];
        if ($missing !== []) $problems[] = 'Some priced reservations lack a recorded room-night entry.';
        if ($duplicate !== []) $problems[] = 'Some reservations have duplicate room-night entries.';
        if ($unpriced !== []) $problems[] = 'Some reservations are unpriced and cannot be financially audited.';
        if ($openShifts > 0) $problems[] = 'There are cashier shifts still open.';
        if ($pendingPayments > 0) $problems[] = 'There is unreviewed payment evidence.';
        if ($unreconciledShifts > 0) $problems[] = 'Some closed cashier shifts await independent reconciliation.';

        return [
            'data' => [
                'hotelId' => (string) $hotel->id,
                'businessDate' => $date,
                'hotelDate' => $today,
                'currency' => $currency,
                'roomCount' => (int) $roomCount,
                'scheduledRoomNights' => $eligible->count(),
                'currentCheckedInOnScheduledDate' => $eligible->where('lifecycle_state', 'checked_in')->count(),
                'pricedRoomNights' => $folioRows->count(),
                'postedRoomChargesMinor' => $postedMinor,
                'missingNightEntries' => $missing,
                'duplicateNightEntries' => $duplicate,
                'unpricedBookings' => $unpriced,
                'openCashierShifts' => $openShifts,
                'unreconciledCashierShifts' => $unreconciledShifts,
                'pendingPaymentEvidence' => $pendingPayments,
                'issues' => $problems,
                'preflightClear' => $problems === [],
                'roomEntriesAlreadyPostedAtBooking' => true,
                'financialDayClosed' => false,
                'bankOrGatewayVerified' => false,
                'historicalOccupancyVerified' => false,
                'taxesCalculated' => false,
            ],
        ];
    }
}

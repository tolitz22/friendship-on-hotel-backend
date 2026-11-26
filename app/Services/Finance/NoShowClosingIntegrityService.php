<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Current carry-forward integrity check, not an as-of trial balance. */
final class NoShowClosingIntegrityService
{
    public function report(Hotel $hotel, string $date, int $actorId): array
    {
        return DB::transaction(function () use ($hotel, $date, $actorId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $hotelId = (int) $hotel->id;
            DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
            $issues = [];
            $journalReport = app(NoShowJournalIntegrityService::class)->report($hotel);
            foreach ($journalReport['issues'] as $issue) {
                $issues[] = $issue;
            }
            $bookings = DB::table('reservation_records as r')
                ->join('reservation_folios as f', function ($join): void {
                    $join->on('f.reservation_record_id', '=', 'r.id')->on('f.hotel_id', '=', 'r.hotel_id');
                })
                ->where('r.hotel_id', $hotelId)->where('r.lifecycle_state', 'no_show')
                ->where('r.check_in', '<=', $date)->select('r.id', 'f.currency')->orderBy('r.id')->get();
            foreach ($bookings as $booking) {
                $id = (int) $booking->id;
                if ($booking->currency !== 'PHP') {
                    $issues[] = ['reason' => 'unsupported_folio_currency', 'bookingId' => (string) $id];
                    continue;
                }
                $ledger = app(NoShowLedgerReconciliationService::class)->report($hotel, $id, $actorId);
                if (!$ledger['reconciled']) {
                    $issues[] = ['reason' => 'ledger_not_reconciled', 'bookingId' => (string) $id,
                        'details' => $ledger['issues']];
                }
                $history = app(NoShowCorrectionJournalHistory::class)->report($hotel, $id, $actorId);
                foreach ($history['corrections'] as $correction) {
                    if ($correction['businessDate'] <= $date && $correction['status'] !== 'posted_verified') {
                        $issues[] = ['reason' => 'correction_requires_resolution', 'bookingId' => (string) $id,
                            'proposalId' => $correction['proposalId'], 'kind' => $correction['kind'],
                            'status' => $correction['status']];
                    }
                }
            }
            return ['scope' => 'current_no_show_carry_forward', 'businessDate' => $date,
                'scannedBookingCount' => $bookings->count(), 'scannedJournalCount' => $journalReport['scannedJournalCount'],
                'issues' => $issues, 'integrityValid' => $issues === [], 'readOnly' => true];
        }, 3);
    }

    public function assertClear(array $report): void
    {
        if (!$report['integrityValid']) {
            throw ValidationException::withMessages(['no_show_journal_integrity' => self::blockerMessage($report)]);
        }
    }

    public static function blockerMessage(array $report): string
    {
        return count($report['issues']).' no-show financial integrity issue(s) must be resolved before closing. Review affected journals, bookings and correction proposals.';
    }
}

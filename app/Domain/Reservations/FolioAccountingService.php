<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A staff-maintained provisional ledger. Staff confirmation is not bank or
 * processor settlement. All writes lock the folio row first.
 */
final class FolioAccountingService
{
    public function findFolio(Hotel $hotel, int $bookingId, bool $lock = false): object
    {
        abort_unless(DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('id', $bookingId)->exists(), 404);
        $query = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
            ->where('reservation_record_id', $bookingId);
        $folio = $lock ? $query->lockForUpdate()->first() : $query->first();
        abort_unless($folio !== null, 404, 'This reservation has no priced folio.');
        return $folio;
    }

    public function summary(Hotel $hotel, int $bookingId): array
    {
        $folio = $this->findFolio($hotel, $bookingId);
        return $this->summaryFor($folio);
    }

    public function summaryFor(object $folio): array
    {
        $entries = DB::table('folio_entries')->where('hotel_id', $folio->hotel_id)
            ->where('folio_id', $folio->id)->get(['amount_minor']);
        $adjustments = DB::table('folio_adjustments')->where('hotel_id', $folio->hotel_id)
            ->where('folio_id', $folio->id)->get(['kind', 'amount_minor']);
        $payments = DB::table('folio_payment_records')->where('hotel_id', $folio->hotel_id)
            ->where('folio_id', $folio->id)->get(['status', 'amount_minor']);

        // Using PHP integer sums avoids database-specific coercion of large DECIMAL values.
        $roomSubtotal = 0;
        foreach ($entries as $entry) {
            $roomSubtotal = $this->checkedAdd($roomSubtotal, (int) $entry->amount_minor);
        }
        $debits = 0;
        $credits = 0;
        foreach ($adjustments as $entry) {
            if ($entry->kind === 'debit') {
                $debits = $this->checkedAdd($debits, (int) $entry->amount_minor);
            } else {
                $credits = $this->checkedAdd($credits, (int) $entry->amount_minor);
            }
        }
        $pending = 0;
        $staffConfirmed = 0;
        foreach ($payments as $payment) {
            if ($payment->status === 'pending_review') {
                $pending = $this->checkedAdd($pending, (int) $payment->amount_minor);
            } elseif ($payment->status === 'staff_confirmed') {
                $staffConfirmed = $this->checkedAdd($staffConfirmed, (int) $payment->amount_minor);
            }
        }
        $net = $this->checkedAdd($roomSubtotal, $debits) - $credits;
        return [
            'folioId' => (string) $folio->id,
            'currency' => $folio->currency,
            'roomSubtotalMinor' => $roomSubtotal,
            'additionalDebitsMinor' => $debits,
            'creditsMinor' => $credits,
            'netRecordedChargesMinor' => $net,
            'pendingReviewPaymentsMinor' => $pending,
            'staffConfirmedPaymentsMinor' => $staffConfirmed,
            'provisionalDifferenceMinor' => $net - $staffConfirmed,
            'taxesCalculated' => false,
            'feesCalculated' => false,
            'finalPayableTotalKnown' => false,
            'bankOrGatewayVerified' => false,
            'isSettled' => false,
        ];
    }

    public function adjust(Hotel $hotel, int $bookingId, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actor, $input): array {
            $folio = $this->findFolio($hotel, $bookingId, true);
            $fingerprint = $this->fingerprint([
                $bookingId, $input['kind'], (int) $input['amountMinor'], trim($input['reason']),
            ]);
            $previous = DB::table('folio_adjustments')->where('hotel_id', $hotel->id)
                ->where('request_key', $input['requestKey'])->first();
            if ($previous !== null) {
                if (! hash_equals($previous->payload_sha256, $fingerprint)) {
                    throw new HttpException(409, 'Adjustment key is already used for different details.');
                }
                return ['id' => (string) $previous->id, 'replayed' => true];
            }
            $id = DB::table('folio_adjustments')->insertGetId([
                'hotel_id' => $hotel->id,
                'folio_id' => $folio->id,
                'request_key' => $input['requestKey'],
                'payload_sha256' => $fingerprint,
                'kind' => $input['kind'],
                'amount_minor' => $input['amountMinor'],
                'reason' => trim($input['reason']),
                'actor_id' => $actor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return ['id' => (string) $id, 'replayed' => false];
        }, 3);
    }

    public function recordPayment(Hotel $hotel, int $bookingId, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actor, $input): array {
            $folio = $this->findFolio($hotel, $bookingId, true);
            $reference = trim($input['reference']);
            $fingerprint = $this->fingerprint([
                $bookingId, $input['method'], $reference, (int) $input['amountMinor'],
            ]);
            $previous = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('request_key', $input['requestKey'])->first();
            if ($previous !== null) {
                if (! hash_equals($previous->payload_sha256, $fingerprint)) {
                    throw new HttpException(409, 'Payment key is already used for different details.');
                }
                return ['id' => (string) $previous->id, 'status' => $previous->status, 'replayed' => true];
            }
            if (DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('method', $input['method'])->where('reference', $reference)->exists()) {
                throw new HttpException(409, 'Payment reference already recorded for this hotel and method.');
            }
            $id = DB::table('folio_payment_records')->insertGetId([
                'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                'request_key' => $input['requestKey'], 'payload_sha256' => $fingerprint,
                'method' => $input['method'], 'reference' => $reference,
                'amount_minor' => $input['amountMinor'], 'status' => 'pending_review',
                'recorded_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => (string) $id, 'status' => 'pending_review', 'replayed' => false];
        }, 3);
    }

    public function review(Hotel $hotel, int $bookingId, int $paymentId, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $paymentId, $actor, $input): array {
            // Serialize cash evidence confirmation with drawer closure and reconciliation.
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $folio = $this->findFolio($hotel, $bookingId, true);
            $payment = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->where('id', $paymentId)->lockForUpdate()->first();
            abort_unless($payment !== null, 404);
            if ($payment->status !== 'pending_review') {
                throw new HttpException(409, 'Only pending payment records may be reviewed.');
            }
            if ($payment->method === 'bank_transfer' && (int) $payment->recorded_by === $actor
                && $input['decision'] === 'confirm') {
                throw new HttpException(403, 'Bank transfer confirmation requires a different administrator.');
            }

            DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('id', $paymentId)->update([
                    'status' => $input['decision'] === 'confirm' ? 'staff_confirmed' : 'rejected',
                    'reviewed_by' => $actor, 'review_note' => trim($input['note']),
                    'updated_at' => now(),
                ]);
            return ['id' => (string) $paymentId,
                'status' => $input['decision'] === 'confirm' ? 'staff_confirmed' : 'rejected'];
        }, 3);
    }

    public function entries(Hotel $hotel, int $bookingId): array
    {
        $folio = $this->findFolio($hotel, $bookingId);
        return [
            'summary' => $this->summaryFor($folio),
            'adjustments' => DB::table('folio_adjustments')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->orderBy('id')->get()
                ->map(fn (object $row): array => [
                    'id' => (string) $row->id, 'kind' => $row->kind,
                    'amountMinor' => (int) $row->amount_minor,
                    'reason' => $row->reason, 'actorId' => (string) $row->actor_id,
                    'createdAt' => $row->created_at,
                ])->values(),
            'payments' => DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->orderBy('id')->get()
                ->map(fn (object $row): array => [
                    'id' => (string) $row->id, 'method' => $row->method,
                    'reference' => $row->reference, 'amountMinor' => (int) $row->amount_minor,
                    'status' => $row->status, 'recordedBy' => (string) $row->recorded_by,
                    'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
                    'reviewNote' => $row->review_note, 'createdAt' => $row->created_at,
                ])->values(),
        ];
    }

    /**
     * Credit the full room subtotal and release inventory only when no money or
     * manual adjustment has ever been recorded. Not a refund.
     */
    public function cancelUnpaidPriced(Hotel $hotel, int $bookingId, int $actor): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actor): array {
            $snapshot = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->first();
            abort_unless($snapshot !== null, 404);
            DB::table('room_types')->where('hotel_id', $hotel->id)
                ->where('id', $snapshot->room_type_id)->lockForUpdate()->firstOrFail();

            $booking = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking !== null, 404);
            if ($booking->lifecycle_state !== 'confirmed') {
                throw new HttpException(409, 'Only confirmed reservations may be cancelled.');
            }
            if (now($hotel->timezone ?: 'Asia/Manila')->toDateString() >= $booking->check_in) {
                throw new HttpException(409, 'A priced cancellation after arrival requires manual financial review.');
            }

            $folio = $this->findFolio($hotel, $bookingId, true);
            // Once financial journals have been linked to a priced room night,
            // cancellation must use an explicit append-only accounting reversal.
            if (DB::table('financial_folio_night_assessments')
                ->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->exists()) {
                throw new HttpException(409, 'Accounting has been assessed for this folio; use reviewed financial reversals before cancellation.');
            }
            if (DB::table('folio_payment_records')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->exists()) {
                throw new HttpException(409, 'Payment evidence exists; a refund/reconciliation workflow is required.');
            }
            if (DB::table('folio_adjustments')->where('hotel_id', $hotel->id)
                ->where('folio_id', $folio->id)->exists()) {
                throw new HttpException(409, 'Adjusted folios require manual cancellation reconciliation.');
            }

            $summary = $this->summaryFor($folio);
            $amount = $summary['roomSubtotalMinor'];
            if ($amount > 0) {
                DB::table('folio_adjustments')->insert([
                    'hotel_id' => $hotel->id, 'folio_id' => $folio->id,
                    'request_key' => 'auto-cancel-booking-'.$bookingId,
                    'payload_sha256' => $this->fingerprint([$bookingId, 'cancel', $amount]),
                    'kind' => 'credit',
                    'amount_minor' => $amount,
                    'reason' => 'Full room-charge reversal for unpaid pre-arrival cancellation',
                    'actor_id' => $actor,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $bookingId)->update(['lifecycle_state' => 'cancelled', 'updated_at' => now()]);
            DB::table('reservation_booking_events')->insert([
                'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
                'actor_id' => $actor, 'event_type' => 'cancelled_priced_unpaid',
                'old_state' => 'confirmed', 'new_state' => 'cancelled',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return [
                'id' => (string) $bookingId,
                'status' => 'cancelled',
                'roomChargesReversedMinor' => $amount,
                'refundIssued' => false,
            ];
        }, 3);
    }

    private function fingerprint(array $fields): string
    {
        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new HttpException(422, 'Financial values exceed supported accounting limits.');
        }
        return $left + $right;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FolioAccountingService;
use App\Domain\Reservations\FolioSettlementService;
use Illuminate\Http\Request;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A non-posting, per-currency group view of the original reservation folios.
 * A group reference is not a payment and cannot verify a payment.
 */
final class GroupFinanceController
{
    public function allocate(Request $request, Hotel $hotel, string $group, string $booking,
        string $payment, FolioSettlementService $settlement): JsonResponse
    {
        $input = $request->validate([
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'folioEntryId' => ['nullable', 'integer', 'min:1'],
        ]);
        $record = DB::table('group_reservations')->where('hotel_id', $hotel->id)
            ->where('id', $group)->first();
        abort_unless($record !== null, 404);
        abort_unless(DB::table('group_reservation_members')->where('hotel_id', $hotel->id)
            ->where('group_reservation_id', $group)
            ->where('reservation_record_id', $booking)->exists(), 404);

        // Existing service locks the folio, requires independently verified
        // payment evidence, caps allocations and honors settlement signoffs.
        $result = $settlement->allocate($hotel, (int) $booking, (int) $payment,
            (int) $request->user()->id, $input['requestKey'], 'group',
            $record->reference, (int) $input['amountMinor'],
            isset($input['folioEntryId']) ? (int) $input['folioEntryId'] : null);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function __invoke(Hotel $hotel, string $group, FolioAccountingService $accounting): JsonResponse
    {
        abort_unless(DB::table('group_reservations')->where('hotel_id', $hotel->id)
            ->where('id', $group)->exists(), 404);
        $rows = DB::table('group_reservation_members as gm')
            ->join('reservation_records as booking', function ($join): void {
                $join->on('booking.id', '=', 'gm.reservation_record_id')
                    ->on('booking.hotel_id', '=', 'gm.hotel_id');
            })
            ->leftJoin('reservation_folios as folio', function ($join): void {
                $join->on('folio.reservation_record_id', '=', 'booking.id')
                    ->on('folio.hotel_id', '=', 'booking.hotel_id');
            })
            ->where('gm.hotel_id', $hotel->id)->where('gm.group_reservation_id', $group)
            ->orderBy('gm.position')
            ->get(['booking.id as booking_id', 'booking.reference',
                'folio.id as folio_id', 'folio.currency']);

        $totals = [];
        $individual = [];
        foreach ($rows as $row) {
            if ($row->folio_id === null) {
                $individual[] = ['bookingId' => (string) $row->booking_id,
                    'reference' => $row->reference, 'priced' => false];
                continue;
            }
            $summary = $accounting->summaryFor((object) [
                'id' => $row->folio_id, 'hotel_id' => $hotel->id, 'currency' => $row->currency,
            ]);
            $verifiedAllocated = (int) DB::table('folio_settlement_allocations as alloc')
                ->join('folio_settlement_verifications as verify', function ($join): void {
                    $join->on('verify.payment_record_id', '=', 'alloc.payment_record_id')
                        ->on('verify.folio_id', '=', 'alloc.folio_id')
                        ->on('verify.hotel_id', '=', 'alloc.hotel_id');
                })
                ->where('alloc.hotel_id', $hotel->id)->where('alloc.folio_id', $row->folio_id)
                ->where('verify.status', 'verified')->sum('alloc.amount_minor');
            $groupAllocated = (int) DB::table('folio_settlement_allocations as alloc')
                ->join('folio_settlement_verifications as verify', function ($join): void {
                    $join->on('verify.payment_record_id', '=', 'alloc.payment_record_id')
                        ->on('verify.folio_id', '=', 'alloc.folio_id')
                        ->on('verify.hotel_id', '=', 'alloc.hotel_id');
                })
                ->where('alloc.hotel_id', $hotel->id)->where('alloc.folio_id', $row->folio_id)
                ->where('alloc.payer_kind', 'group')
                ->where('alloc.payer_reference', (string) DB::table('group_reservations')
                    ->where('hotel_id', $hotel->id)->where('id', $group)->value('reference'))
                ->where('verify.status', 'verified')->sum('alloc.amount_minor');
            $eligiblePayments = DB::table('folio_payment_records as pay')
                ->join('folio_settlement_verifications as verify', function ($join): void {
                    $join->on('verify.payment_record_id', '=', 'pay.id')
                        ->on('verify.folio_id', '=', 'pay.folio_id')
                        ->on('verify.hotel_id', '=', 'pay.hotel_id');
                })
                ->where('pay.hotel_id', $hotel->id)->where('pay.folio_id', $row->folio_id)
                ->where('pay.status', 'staff_confirmed')->where('verify.status', 'verified')
                ->orderBy('pay.id')->get(['pay.id', 'pay.reference', 'pay.amount_minor'])
                ->map(function (object $payment) use ($hotel): array {
                    $allocated = (int) DB::table('folio_settlement_allocations')
                        ->where('hotel_id', $hotel->id)
                        ->where('payment_record_id', $payment->id)->sum('amount_minor');
                    $reservedRefunds = (int) DB::table('folio_refund_requests')
                        ->where('hotel_id', $hotel->id)
                        ->where('payment_record_id', $payment->id)
                        ->whereIn('status', ['requested', 'approved'])->sum('amount_minor');
                    return ['paymentId' => (string) $payment->id,
                        'reference' => (string) $payment->reference,
                        'remainingMinor' => max(0, (int) $payment->amount_minor - $allocated - $reservedRefunds)];
                })->values();
            $paymentStages = DB::table('folio_payment_records as pay')
                ->leftJoin('folio_settlement_verifications as verify', function ($join): void {
                    $join->on('verify.payment_record_id', '=', 'pay.id')
                        ->on('verify.folio_id', '=', 'pay.folio_id')
                        ->on('verify.hotel_id', '=', 'pay.hotel_id');
                })
                ->where('pay.hotel_id', $hotel->id)->where('pay.folio_id', $row->folio_id)
                ->whereIn('pay.status', ['pending_review', 'staff_confirmed'])
                ->get(['pay.id', 'pay.status', 'pay.amount_minor', 'verify.status as verification_status']);
            $pendingCount = $paymentStages->where('status', 'pending_review')->count();
            $confirmedCount = $paymentStages->where('status', 'staff_confirmed')->count();
            $verifiedCount = $paymentStages->filter(static fn (object $payment): bool =>
                $payment->status === 'staff_confirmed' && $payment->verification_status === 'verified')->count();
            $pendingAmount = (int) $summary['pendingReviewPaymentsMinor'];
            $confirmedAmount = (int) $summary['staffConfirmedPaymentsMinor'];
            $recordedAmount = $pendingAmount + $confirmedAmount;
            $signed = DB::table('folio_settlement_signoffs')->where('hotel_id', $hotel->id)
                ->where('folio_id', $row->folio_id)->exists();

            $currency = $row->currency;
            if (!isset($totals[$currency])) {
                $totals[$currency] = ['currency' => $currency, 'netRecordedChargesMinor' => 0,
                    'pendingReviewPaymentsMinor' => 0, 'recordedPaymentsMinor' => 0,
                    'staffConfirmedPaymentsMinor' => 0, 'verifiedAllocatedMinor' => 0,
                    'verifiedGroupPayerAllocatedMinor' => 0, 'unallocatedNetChargesMinor' => 0,
                    'balanceAfterRecordedPaymentsMinor' => 0,
                    'pricedFolioCount' => 0];
            }
            $net = (int) $summary['netRecordedChargesMinor'];
            $totals[$currency]['netRecordedChargesMinor'] += $net;
            $totals[$currency]['pendingReviewPaymentsMinor'] += $pendingAmount;
            $totals[$currency]['recordedPaymentsMinor'] += $recordedAmount;
            $totals[$currency]['balanceAfterRecordedPaymentsMinor'] += $net - $recordedAmount;
            $totals[$currency]['staffConfirmedPaymentsMinor'] += $confirmedAmount;
            $totals[$currency]['verifiedAllocatedMinor'] += $verifiedAllocated;
            $totals[$currency]['verifiedGroupPayerAllocatedMinor'] += $groupAllocated;
            $totals[$currency]['unallocatedNetChargesMinor'] += $net - $verifiedAllocated;
            $totals[$currency]['pricedFolioCount']++;
            $individual[] = [
                'bookingId' => (string) $row->booking_id, 'reference' => $row->reference,
                'folioId' => (string) $row->folio_id, 'priced' => true, 'currency' => $currency,
                'netRecordedChargesMinor' => $net,
                'pendingReviewPaymentsMinor' => $pendingAmount,
                'recordedPaymentsMinor' => $recordedAmount,
                'balanceAfterRecordedPaymentsMinor' => $net - $recordedAmount,
                'pendingReviewPaymentCount' => $pendingCount,
                'staffConfirmedPaymentCount' => $confirmedCount,
                'independentlyVerifiedPaymentCount' => $verifiedCount,
                'staffConfirmedPaymentsMinor' => $confirmedAmount,
                'verifiedAllocatedMinor' => $verifiedAllocated,
                'verifiedGroupPayerAllocatedMinor' => $groupAllocated,
                'unallocatedNetChargesMinor' => $net - $verifiedAllocated,
                'signed' => $signed,
                'eligiblePayments' => $eligiblePayments,
            ];
        }

        return response()->json([
            'data' => ['hotelId' => (string) $hotel->id, 'groupId' => (string) $group,
                'currencies' => array_values($totals), 'rooms' => $individual],
            'meta' => [
                'readOnly' => true, 'finalInvoice' => false, 'postsTransactions' => false,
                'verifiedMeansIndependentStaffEvidenceReview' => true,
                'bankOrGatewayVerificationClaimed' => false,
                'groupPayerAllocationEndpoint' => 'Use the existing booking payment allocation endpoint after independent verification.',
            ],
        ]);
    }
}

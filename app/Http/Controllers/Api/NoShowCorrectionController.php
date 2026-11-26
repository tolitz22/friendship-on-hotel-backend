<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Services\Finance\NoShowRoomNightCorrectionPreview;
use App\Services\Finance\NoShowRoomNightCorrectionDecisionService;
use App\Services\Finance\NoShowFeeReversalPreview;
use App\Services\Finance\NoShowFeeCorrectionDecisionService;
use App\Services\Finance\NoShowCorrectionJournalHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class NoShowCorrectionController
{
    public function show(Request $request, Hotel $hotel, string $booking): JsonResponse
    {
        $input = $request->validate(['replacementMinor' => ['nullable', 'integer', 'min:0', 'max:1000000000000']]);
        return DB::transaction(function () use ($request, $hotel, $booking, $input): JsonResponse {
            $actor = $this->authorizeActor($request, $hotel, $booking);
            $history = app(NoShowCorrectionJournalHistory::class)->report($hotel, (int) $booking, $actor);
            $room = app(NoShowRoomNightCorrectionPreview::class)->preview($hotel, (int) $booking);
            $fee = app(NoShowFeeReversalPreview::class)->preview($hotel, (int) $booking, $actor, (int) ($input['replacementMinor'] ?? 0));
            $folioId = DB::table('reservation_folios')->where('hotel_id', $hotel->id)->where('reservation_record_id', $booking)->value('id');
            if (DB::table('folio_payment_records')->where('hotel_id', $hotel->id)->where('folio_id', $folioId)->exists()) {
                $room['blockers'][] = 'existing_payment_evidence_requires_reconciliation';
            }
            $room['eligibleForReview'] = $room['requiresCorrection'] && $room['currency'] === 'PHP'
                && $room['blockers'] === [] && collect($room['nights'])->every(fn ($night) => $night['status'] === 'approved'
                    && $night['issues'] === [] && $night['journals'] !== []);
            $groups = $history['corrections'];
            foreach ($groups as &$group) {
                if ($group['kind'] === 'fee') {
                    $review = DB::table('no_show_fee_correction_reviews')->where('proposal_id', $group['proposalId'])->first();
                    $group['replacementVatMinor'] = $review === null ? null : (int) $review->replacement_vat_minor;
                    $group['replacementRevenueMinor'] = $review === null ? null : (int) $review->replacement_revenue_minor;
                    $group['taxClassification'] = $review?->tax_classification;
                }
            }
            unset($group);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->first();
            $open = $clock !== null && $clock->status === 'open'
                && !DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->where('business_date', $clock->business_date)->exists();
            return response()->json(['data' => ['bookingId' => $booking, 'currency' => 'PHP',
                'businessDate' => $clock?->business_date, 'open' => $open,
                'roomReviewEnabled' => config('financial_accounting.enable_no_show_room_correction_review', false) && \App\Domain\Reservations\NoShowWorkflow::enabled((int) $hotel->id),
                'roomPostingEnabled' => config('financial_accounting.enable_no_show_room_correction_posting', false) && \App\Domain\Reservations\NoShowWorkflow::enabled((int) $hotel->id),
                'feeReviewEnabled' => config('financial_accounting.enable_no_show_fee_correction_review', false) && \App\Domain\Reservations\NoShowWorkflow::enabled((int) $hotel->id),
                'feePostingEnabled' => config('financial_accounting.enable_no_show_fee_correction_posting', false) && \App\Domain\Reservations\NoShowWorkflow::enabled((int) $hotel->id),
                'room' => $room, 'fee' => $fee, 'corrections' => $groups]])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function propose(Request $request, Hotel $hotel, string $booking, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['room', 'fee'], true), 404);
        $input = $request->validate(['reason' => ['required', 'string', 'min:15', 'max:1000'],
            'replacementMinor' => [$kind === 'fee' ? 'required' : 'nullable', 'integer', 'min:0', 'max:1000000000000']]);
        return DB::transaction(function () use ($request, $hotel, $booking, $kind, $input): JsonResponse {
            $actor = $this->authorizeActor($request, $hotel, $booking);
            $id = $kind === 'room'
                ? app(NoShowRoomNightCorrectionDecisionService::class)->propose($hotel, (int) $booking, $actor, $input['reason'])
                : app(NoShowFeeCorrectionDecisionService::class)->propose($hotel, (int) $booking, $actor, (int) $input['replacementMinor'], $input['reason']);
            return response()->json(['data' => ['proposalId' => (string) $id]], 201);
        }, 3);
    }

    public function decide(Request $request, Hotel $hotel, string $booking, string $kind, string $proposal, string $action): JsonResponse
    {
        abort_unless(in_array($kind, ['room', 'fee'], true) && in_array($action, ['approve', 'post'], true), 404);
        $rules = $action === 'post' ? ['acknowledged' => ['required', 'accepted']] : ['reason' => ['required', 'string', 'min:15', 'max:1000']];
        if ($kind === 'fee' && $action === 'approve') {
            $rules['taxClassification'] = ['required', 'in:vat_standard,vat_zero,vat_exempt,non_vat'];
            $rules['taxBasis'] = ['required', 'string', 'min:15', 'max:1000'];
        }
        $input = $request->validate($rules);
        return DB::transaction(function () use ($request, $hotel, $booking, $kind, $proposal, $action, $input): JsonResponse {
            $actor = $this->authorizeActor($request, $hotel, $booking);
            abort_unless(DB::table('no_show_'.$kind.'_correction_proposals')->where('hotel_id', $hotel->id)
                ->where('booking_id', $booking)->where('id', $proposal)->exists(), 404);
            if ($kind === 'room') {
                $service = app(NoShowRoomNightCorrectionDecisionService::class);
                $result = $action === 'post' ? $service->post($hotel, (int) $proposal, $actor)
                    : ['reviewId' => (string) $service->approve($hotel, (int) $proposal, $actor, $input['reason'])];
            } else {
                $service = app(NoShowFeeCorrectionDecisionService::class);
                $result = $action === 'post' ? $service->applyApprovedCorrection($hotel, (int) $proposal, $actor)
                    : ['reviewId' => (string) $service->approve($hotel, (int) $proposal, $actor, $input['taxClassification'], $input['taxBasis'], $input['reason'])];
            }
            return response()->json(['data' => $result]);
        }, 3);
    }

    private function authorizeActor(Request $request, Hotel $hotel, string $booking): int
    {
        app(ReadinessWriteGuard::class)->lockHotel($hotel);
        $actor = (int) $request->user()->id;
        abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)->where('user_id', $actor)
            ->where('role', 'admin')->where('is_active', true)->exists()
            && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
        abort_unless(DB::table('reservation_records')->where('hotel_id', $hotel->id)->where('id', $booking)
            ->where('lifecycle_state', 'no_show')->exists(), 404);
        return $actor;
    }
}

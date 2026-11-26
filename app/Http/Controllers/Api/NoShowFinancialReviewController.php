<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\NoShowFinancialReviewService;
use App\Domain\Reservations\NoShowWorkflow;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Finance\NoShowFinancialClearanceService;

final class NoShowFinancialReviewController
{
    public function show(Hotel $hotel, string $booking, NoShowFinancialReviewService $service): JsonResponse
    {
        return response()->json(['data' => $service->preview($hotel, (int) $booking)]);
    }

    public function ledgerReconciliation(Request $request, Hotel $hotel, string $booking,
        \App\Services\Finance\NoShowLedgerReconciliationService $service): JsonResponse
    {
        return response()->json(['data' => $service->report(
            $hotel, (int) $booking, (int) $request->user()->id
        )])->header('Cache-Control', 'private, no-store');
    }

    public function apply(Request $request, Hotel $hotel, string $booking, NoShowFinancialReviewService $service): JsonResponse
    {
        $data = $request->validate([
            'reviewFingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'reason' => ['required', 'string', 'min:12', 'max:500'],
            'manualFeeMinor' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
        ]);
        return response()->json(['data' => $service->apply(
            $hotel, (int) $booking, (int) $request->user()->id,
            $data['reviewFingerprint'], $data['reason'],
            isset($data['manualFeeMinor']) ? (int) $data['manualFeeMinor'] : null
        )], 201);
    }

    public function approve(Request $request, Hotel $hotel, string $booking, string $review, NoShowFinancialClearanceService $service): JsonResponse
    {
        abort_unless(NoShowWorkflow::enabled((int) $hotel->id), 409, 'No-show financial approval is not enabled.');
        $data = $request->validate([
            'taxClassification' => ['required', 'in:vat_standard,vat_zero,vat_exempt,non_vat'],
            'taxBasis' => ['required', 'string', 'min:12', 'max:1000'],
        ]);
        return response()->json(['data' => $service->approve(
            $hotel, (int) $booking, (int) $review, (int) $request->user()->id,
            $data['taxClassification'], $data['taxBasis']
        )], 201);
    }

}

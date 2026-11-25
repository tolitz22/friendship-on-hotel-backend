<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\NoShowFinancialReviewService;
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

    public function apply(Request $request, Hotel $hotel, string $booking, NoShowFinancialReviewService $service): JsonResponse
    {
        $data = $request->validate([
            'reviewFingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'reason' => ['required', 'string', 'min:12', 'max:500'],
        ]);
        return response()->json(['data' => $service->apply(
            $hotel, (int) $booking, (int) $request->user()->id,
            $data['reviewFingerprint'], $data['reason']
        )], 201);
    }

    public function approve(Request $request, Hotel $hotel, string $booking, string $review, NoShowFinancialClearanceService $service): JsonResponse
    {
        abort_unless(config('booking.no_show_adjustments_enabled', false), 409, 'No-show financial approval is not enabled.');
        $data = $request->validate([
            'vatMinor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'taxBasis' => ['required', 'string', 'min:12', 'max:1000'],
        ]);
        return response()->json(['data' => $service->approve(
            $hotel, (int) $booking, (int) $review, (int) $request->user()->id,
            (int) $data['vatMinor'], $data['taxBasis']
        )], 201);
    }

}

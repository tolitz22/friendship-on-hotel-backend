<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\FinancialBusinessDayCloseService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrator-only financial day close. The immutable operational night-audit
 * review must be cleared before the financial business clock can advance.
 */
final class FinancialBusinessDayController
{
    public function show(Hotel $hotel): JsonResponse
    {
        $clock = DB::table('financial_business_clocks')
            ->where('hotel_id', $hotel->id)->first();

        if ($clock === null) {
            return response()->json(['message' => 'Financial business clock is not configured.'], 409);
        }

        $date = CarbonImmutable::parse($clock->business_date)->toDateString();
        $review = DB::table('night_audit_reviews')
            ->where('hotel_id', $hotel->id)->where('business_date', $date)
            ->first(['status', 'reviewed_by', 'reviewed_at']);

        return response()->json(['data' => [
            'businessDate' => $date,
            'status' => $clock->status,
            'version' => (int) $clock->version,
            'nightAuditReview' => $review === null ? null : [
                'status' => $review->status,
                'reviewedBy' => (string) $review->reviewed_by,
                'reviewedAt' => $review->reviewed_at,
            ],
            'canClose' => $clock->status === 'open'
                && $review !== null && $review->status === 'reviewed'
                && !DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                    ->where('business_date', $date)->exists(),
        ]]);
    }

    public function close(Request $request, Hotel $hotel, FinancialBusinessDayCloseService $service): JsonResponse
    {
        $validated = $request->validate([
            'businessDate' => ['required', 'date_format:Y-m-d'],
            'acknowledged' => ['required', 'accepted'],
        ]);
        $date = $validated['businessDate'];

        // The review is append-only; the financial service independently checks
        // and locks the clock, period, journal totals and previous closes.
        $reviewed = DB::table('night_audit_reviews')
            ->where('hotel_id', $hotel->id)->where('business_date', $date)
            ->where('status', 'reviewed')->exists();
        if (!$reviewed) {
            throw ValidationException::withMessages([
                'businessDate' => 'An unflagged, completed manager night-audit review is required before financial day close.',
            ]);
        }

        $result = $service->close((int) $hotel->id, $date, (int) $request->user()->id);

        return response()->json(['data' => [
            'closedBusinessDate' => $result['closed_business_date'],
            'nextBusinessDate' => $result['next_business_date'],
            'reconciliationSnapshot' => $result['snapshot'],
        ]]);
    }
}

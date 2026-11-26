<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FolioSettlementService;
use App\Domain\Reservations\NoShowRefundEligibility;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Domain\Reservations\NoShowWorkflow;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NoShowRefundOverviewController
{
    public function __invoke(Request $request, Hotel $hotel, string $booking): JsonResponse
    {
        $data = DB::transaction(function () use ($request, $hotel, $booking): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $actor = $request->user()->id;
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)->where('user_id', $actor)
                ->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            abort_unless(DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('id', $booking)->where('lifecycle_state', 'no_show')->exists(), 404);
            $statement = app(FolioSettlementService::class)->statement($hotel, (int) $booking);
            abort_unless($statement['currency'] === 'PHP', 409);
            $review = app(NoShowRefundEligibility::class)->review($hotel, (int) $booking);
            $enabled = config('financial_accounting.enable_verified_settlement', false)
                && NoShowWorkflow::enabled((int) $hotel->id) && !$statement['signed']
                && $clock !== null && $clock->status === 'open'
                && !DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
                    ->where('business_date', $clock->business_date)->exists();
            $postingEnabled = $enabled && config('financial_accounting.enable_no_show_refund_posting', false)
                && config('financial_accounting.enable_guest_deposit_posting', false);
            return ['bookingId' => $booking, 'currency' => 'PHP', 'workflowEnabled' => $enabled,
                'postingEnabled' => $postingEnabled, 'reconciliationValid' => $review['reconciliationValid'],
                'maximumRefundMinor' => $review['maximumRefundRequestMinor'],
                'payments' => $review['payments'], 'refunds' => $statement['refunds']];
        }, 3);
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}

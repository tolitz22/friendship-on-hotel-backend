<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\NoShowFinancialReviewService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;

final class NoShowFinancialReviewController
{
    public function show(Hotel $hotel, string $booking, NoShowFinancialReviewService $service): JsonResponse
    {
        return response()->json(['data' => $service->preview($hotel, (int) $booking)]);
    }
}

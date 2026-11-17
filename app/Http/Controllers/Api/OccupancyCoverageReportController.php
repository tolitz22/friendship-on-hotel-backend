<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\OccupancyCoverageReportService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OccupancyCoverageReportController
{
    public function __invoke(Request $request, Hotel $hotel, OccupancyCoverageReportService $service): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'filter' => ['sometimes', 'in:all,missing,assigned,needs_review,flagged,mismatch,verified'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($service->show($hotel, $filters));
    }
}

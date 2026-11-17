<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\NightAuditPreviewService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class NightAuditPreviewController
{
    public function __invoke(Request $request, Hotel $hotel, NightAuditPreviewService $service): JsonResponse
    {
        $input = $request->validate([
            'date' => ['required','date_format:Y-m-d'],
        ]);
        $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        if ($input['date'] > $today) {
            throw ValidationException::withMessages([
                'date' => ['Cannot run a financial preflight for a future hotel-local date.'],
            ]);
        }
        return response()->json($service->preview($hotel, $input['date']));
    }
}

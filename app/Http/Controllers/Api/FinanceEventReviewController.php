<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FinanceEventReviewService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class FinanceEventReviewController
{
    public function index(Hotel $hotel, string $booking, FinanceEventReviewService $service): JsonResponse
    {
        return response()->json(['data' => $service->index($hotel, (int) $booking)]);
    }

    public function match(Request $request, Hotel $hotel, string $booking, FinanceEventReviewService $service): JsonResponse
    {
        $input = $request->validate([
            'eventId' => ['required', 'integer', 'min:1'],
            'paymentRecordId' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if (trim($input['note']) === '') {
            throw ValidationException::withMessages(['note' => ['A review note is required.']]);
        }
        return response()->json(['data' => $service->match(
            $hotel, (int) $booking, (int) $request->user()->id, $input
        )], 201);
    }
}

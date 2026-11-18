<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FolioRefundDisbursementService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FolioRefundDisbursementController
{
    public function index(Hotel $hotel, string $booking, FolioRefundDisbursementService $service): JsonResponse
    {
        return response()->json(['data' => $service->index($hotel, (int) $booking)]);
    }

    public function record(Request $request, Hotel $hotel, string $booking, string $refund,
        FolioRefundDisbursementService $service): JsonResponse
    {
        $input = $request->validate([
            'externalReference' => ['required', 'string', 'min:8', 'max:160'],
        ]);
        return response()->json(['data' => $service->record(
            $hotel, (int) $booking, (int) $refund, (int) $request->user()->id,
            trim($input['externalReference']),
        )], 201);
    }

    public function review(Request $request, Hotel $hotel, string $booking, string $evidence,
        FolioRefundDisbursementService $service): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:verify,reject'],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        return response()->json(['data' => $service->review(
            $hotel, (int) $booking, (int) $evidence, (int) $request->user()->id,
            $input['decision'], trim($input['note']),
        )]);
    }
}

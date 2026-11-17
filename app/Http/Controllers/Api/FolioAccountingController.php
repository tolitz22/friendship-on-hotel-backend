<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FolioAccountingService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class FolioAccountingController
{
    public function cancelUnpaid(Request $request, Hotel $hotel, string $booking, FolioAccountingService $accounting): JsonResponse
    {
        return response()->json(['data' => $accounting->cancelUnpaidPriced(
            $hotel, (int) $booking, (int) $request->user()->id
        )]);
    }

    public function show(Hotel $hotel, string $booking, FolioAccountingService $accounting): JsonResponse
    {
        return response()->json(['data' => $accounting->entries($hotel, (int) $booking)]);
    }

    public function adjust(Request $request, Hotel $hotel, string $booking, FolioAccountingService $accounting): JsonResponse
    {
        $data = $request->validate([
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'kind' => ['required', 'in:credit,debit'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if (trim($data['reason']) === '') {
            throw ValidationException::withMessages(['reason' => ['A nonempty reason is required.']]);
        }
        $result = $accounting->adjust($hotel, (int) $booking, (int) $request->user()->id, $data);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function recordPayment(Request $request, Hotel $hotel, string $booking, FolioAccountingService $accounting): JsonResponse
    {
        $data = $request->validate([
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'method' => ['required', 'in:cash,bank_transfer'],
            'reference' => ['required', 'string', 'min:3', 'max:120'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ]);
        if ($data['method'] === 'cash') {
            return response()->json(['message' => 'Cash must be recorded within an open cashier shift using the cash-receipts endpoint.'], 409);
        }
        if (trim($data['reference']) === '') {
            throw ValidationException::withMessages(['reference' => ['A nonempty payment reference is required.']]);
        }
        $result = $accounting->recordPayment($hotel, (int) $booking, (int) $request->user()->id, $data);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function reviewPayment(Request $request, Hotel $hotel, string $booking, string $payment, FolioAccountingService $accounting): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:confirm,reject'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if (trim($data['note']) === '') {
            throw ValidationException::withMessages(['note' => ['Review note is required.']]);
        }
        return response()->json(['data' => $accounting->review(
            $hotel, (int) $booking, (int) $payment, (int) $request->user()->id, $data
        )]);
    }
}

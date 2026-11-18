<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FolioSettlementService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FolioSettlementController
{
    public function statement(Hotel $hotel, string $booking, FolioSettlementService $service): JsonResponse
    {
        return response()->json(['data' => $service->statement($hotel, (int) $booking)]);
    }

    public function verifyPrepare(Request $request, Hotel $hotel, string $booking, string $payment,
        FolioSettlementService $service): JsonResponse
    {
        $input = $request->validate([
            'evidenceKind' => ['required', 'in:bank_statement,cash_shift'],
            'evidenceReference' => ['required', 'string', 'min:6', 'max:160'],
        ]);
        return response()->json(['data' => $service->prepareVerification(
            $hotel, (int) $booking, (int) $payment, (int) $request->user()->id,
            $input['evidenceKind'], $input['evidenceReference'],
        )], 201);
    }

    public function verifyReview(Request $request, Hotel $hotel, string $booking, string $verification,
        FolioSettlementService $service): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:verify,reject'],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        return response()->json(['data' => $service->reviewVerification(
            $hotel, (int) $booking, (int) $verification, (int) $request->user()->id,
            $input['decision'], $input['note'],
        )]);
    }

    public function allocations(Request $request, Hotel $hotel, string $booking, string $payment,
        FolioSettlementService $service): JsonResponse
    {
        $data = $request->validate([
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'payerKind' => ['required', 'in:guest,company,group'],
            'payerReference' => ['required', 'string', 'min:3', 'max:120'],
            'folioEntryId' => ['nullable', 'integer', 'min:1'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ]);
        $result = $service->allocate($hotel, (int) $booking, (int) $payment,
            (int) $request->user()->id, $data['requestKey'], $data['payerKind'],
            $data['payerReference'], (int) $data['amountMinor'],
            isset($data['folioEntryId']) ? (int) $data['folioEntryId'] : null);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function signoff(Request $request, Hotel $hotel, string $booking,
        FolioSettlementService $service): JsonResponse
    {
        $request->validate(['acknowledged' => ['required', 'accepted']]);
        return response()->json(['data' => $service->signoff(
            $hotel, (int) $booking, (int) $request->user()->id,
        )]);
    }
}

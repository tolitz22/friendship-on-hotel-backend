<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ChargePolicyPreviewService;
use App\Domain\Reservations\RefundRequestService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class FinancePolicyController
{
    public function policies(Hotel $hotel, ChargePolicyPreviewService $service): JsonResponse
    {
        return response()->json(['data' => $service->policies($hotel)]);
    }

    public function draft(Request $request, Hotel $hotel, ChargePolicyPreviewService $service): JsonResponse
    {
        $data = $request->validate([
            'taxBasisPoints' => ['required', 'integer', 'min:0', 'max:10000'],
            'serviceBasisPoints' => ['required', 'integer', 'min:0', 'max:10000'],
            'taxLabel' => ['required', 'string', 'min:2', 'max:100'],
            'serviceLabel' => ['required', 'string', 'min:2', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        foreach (['taxLabel', 'serviceLabel', 'reason'] as $key) {
            if (trim($data[$key]) === '') {
                throw ValidationException::withMessages([$key => ['This field cannot be blank.']]);
            }
        }

        return response()->json(['data' => $service->createDraft(
            $hotel, (int) $request->user()->id, $data
        )], 201);
    }

    public function reviewDraft(Request $request, Hotel $hotel, string $policy, ChargePolicyPreviewService $service): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if (trim($input['note']) === '') {
            throw ValidationException::withMessages(['note' => ['A review note is required.']]);
        }

        return response()->json(['data' => $service->reviewDraft(
            $hotel, (int) $request->user()->id, (int) $policy, $input
        )]);
    }

    public function preview(Request $request, Hotel $hotel, string $booking, ChargePolicyPreviewService $service): JsonResponse
    {
        $data = $request->validate(['policyId' => ['required', 'integer', 'min:1']]);
        return response()->json(['data' => $service->preview(
            $hotel, (int) $booking, (int) $data['policyId']
        )]);
    }

    public function refunds(Hotel $hotel, string $booking, RefundRequestService $service): JsonResponse
    {
        return response()->json(['data' => $service->index($hotel, (int) $booking)]);
    }

    public function requestRefund(Request $request, Hotel $hotel, string $booking, RefundRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'paymentRecordId' => ['required', 'integer', 'min:1'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if (trim($data['reason']) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }
        $result = $service->create($hotel, (int) $booking, (int) $request->user()->id, $data);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function reviewRefund(Request $request, Hotel $hotel, string $booking, string $refund, RefundRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if (trim($data['note']) === '') {
            throw ValidationException::withMessages(['note' => ['A review note is required.']]);
        }
        return response()->json(['data' => $service->review(
            $hotel, (int) $booking, (int) $refund, (int) $request->user()->id, $data
        )]);
    }
}

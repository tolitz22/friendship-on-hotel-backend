<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\FinancialTaxPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialTaxPolicyController
{
    public function index(Hotel $hotel, FinancialTaxPolicyService $service): JsonResponse
    {
        return response()->json(['data' => $service->list((int) $hotel->id)]);
    }

    public function draft(Request $request, Hotel $hotel, FinancialTaxPolicyService $service): JsonResponse
    {
        $data = $request->validate([
            'classification' => ['required', 'in:vat_standard,vat_zero,vat_exempt,non_vat'],
            'priceMode' => ['required', 'in:inclusive,exclusive'],
            'rateBps' => ['required', 'integer', 'between:0,10000'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'effectiveUntil' => ['nullable', 'date_format:Y-m-d'],
            'reviewNote' => ['required', 'string', 'min:15', 'max:2000'],
        ]);
        $id = $service->draft((int) $hotel->id, $data['classification'], $data['priceMode'],
            (int) $data['rateBps'], $data['effectiveFrom'], $data['effectiveUntil'] ?? null, $data['reviewNote'], (int) $request->user()->id);
        return response()->json(['data' => ['id' => (string) $id, 'status' => 'draft']], 201);
    }

    public function approve(Request $request, Hotel $hotel, string $policy, FinancialTaxPolicyService $service): JsonResponse
    {
        $request->validate(['acknowledged' => ['required', 'accepted']]);
        $service->approve((int) $hotel->id, (int) $policy, (int) $request->user()->id);
        return response()->json(['data' => ['id' => $policy, 'status' => 'approved']]);
    }

    public function preview(Request $request, Hotel $hotel, FinancialTaxPolicyService $service): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'amountMinor' => ['required', 'integer', 'between:0,1000000000000'],
        ]);
        return response()->json(['data' => $service->preview(
            (int) $hotel->id, $data['date'], (int) $data['amountMinor']
        )]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReadinessEnforcementPolicyService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ReadinessEnforcementPolicyController
{
    public function show(Hotel $hotel, ReadinessEnforcementPolicyService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($hotel)]);
    }

    public function update(Request $request, Hotel $hotel, ReadinessEnforcementPolicyService $service): JsonResponse
    {
        $data = $request->validate([
            'expectedRevision' => ['required', 'integer', 'min:0'],
            'enforceAtCheckIn' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        if (strlen(trim($data['reason'])) < 10) {
            throw ValidationException::withMessages(['reason' => ['Explain the reason for changing this hotel policy.']]);
        }
        return response()->json(['data' => $service->change($hotel, (int) $request->user()->id, $data)]);
    }

    public function history(Request $request, Hotel $hotel, ReadinessEnforcementPolicyService $service): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return response()->json($service->history($hotel, (int) ($data['page'] ?? 1)));
    }
}

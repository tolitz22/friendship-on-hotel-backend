<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\OccupancyInvestigationService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OccupancyInvestigationController
{
    public function show(Request $request, Hotel $hotel, string $verification, OccupancyInvestigationService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($hotel, (int) $verification)]);
    }

    public function store(Request $request, Hotel $hotel, string $verification, OccupancyInvestigationService $service): JsonResponse
    {
        $data = $request->validate([
            'ownerId' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:10', 'max:1500'],
        ]);
        return response()->json(['data' => $service->open($hotel, (int) $request->user()->id, (int) $verification, $data)], 201);
    }

    public function update(Request $request, Hotel $hotel, string $verification, OccupancyInvestigationService $service): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:note,assign,close'],
            'note' => ['required', 'string', 'min:10', 'max:1500'],
            'ownerId' => ['required_if:action,assign', 'integer', 'min:1'],
            'resolution' => ['required_if:action,close', 'in:registration_corrected,observation_unconfirmed,authorized_stay_confirmed,escalated_elsewhere'],
            'evidenceReference' => ['nullable', 'string', 'max:300'],
        ]);
        return response()->json(['data' => $service->update($hotel, (int) $request->user()->id, (int) $verification, $data)]);
    }
}

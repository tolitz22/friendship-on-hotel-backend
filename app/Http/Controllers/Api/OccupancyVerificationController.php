<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\OccupancyVerificationService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OccupancyVerificationController
{
    public function index(Request $request, Hotel $hotel, OccupancyVerificationService $service): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:assigned,submitted,verified,flagged'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'roomId' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($service->listing($hotel, (int) $request->user()->id,
            $this->isManager($request, $hotel), $filters));
    }

    public function show(Request $request, Hotel $hotel, string $verification, OccupancyVerificationService $service): JsonResponse
    {
        return response()->json(['data' => $service->details($hotel,
            (int) $request->user()->id, $this->isManager($request, $hotel), (int) $verification)]);
    }

    public function history(Request $request, Hotel $hotel, string $verification, OccupancyVerificationService $service): JsonResponse
    {
        return response()->json(['data' => $service->history($hotel,
            (int) $request->user()->id, $this->isManager($request, $hotel), (int) $verification)]);
    }

    public function assign(Request $request, Hotel $hotel, OccupancyVerificationService $service): JsonResponse
    {
        $input = $request->validate([
            'roomId' => ['required', 'integer', 'min:1'],
            'assignedUserId' => ['required', 'integer', 'min:1'],
            'inspectionDate' => ['required', 'date_format:Y-m-d'],
        ]);
        return response()->json(['data' => $service->assign($hotel,
            (int) $request->user()->id, $input)], 201);
    }

    public function observe(Request $request, Hotel $hotel, string $verification, OccupancyVerificationService $service): JsonResponse
    {
        $input = $request->validate([
            'observedOccupancy' => ['required', 'in:occupied,vacant,unknown'],
            'note' => ['required', 'string', 'min:5', 'max:1500', 'regex:/\\S/u'],
        ]);
        return response()->json(['data' => $service->observe($hotel,
            (int) $request->user()->id, (int) $verification, $input)]);
    }

    public function review(Request $request, Hotel $hotel, string $verification, OccupancyVerificationService $service): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:verified,flagged'],
            'note' => ['required', 'string', 'min:5', 'max:1500', 'regex:/\\S/u'],
        ]);
        return response()->json(['data' => $service->review($hotel,
            (int) $request->user()->id, (int) $verification, $input)]);
    }

    private function isManager(Request $request, Hotel $hotel): bool
    {
        return $request->user()->tokenCan('hotel:write')
            && $request->user()->memberships()->where('hotel_id', $hotel->id)
                ->where('is_active', true)->where('role', 'admin')->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RoomReadinessService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class RoomReadinessController
{
    public function show(Hotel $hotel, string $room, RoomReadinessService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($hotel, (int) $room)]);
    }

    public function inspect(Request $request, Hotel $hotel, string $room, RoomReadinessService $service): JsonResponse
    {
        $data = $request->validate([
            'cleaningVerified' => ['required', 'boolean'],
            'safetyVerified' => ['required', 'boolean'],
            'amenitiesVerified' => ['required', 'boolean'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        if (trim($data['note']) === '') {
            throw ValidationException::withMessages(['note' => ['A meaningful inspector note is required.']]);
        }
        return response()->json(['data' => $service->inspect(
            $hotel, (int) $room, (int) $request->user()->id, $data
        )], 201);
    }

    public function review(Request $request, Hotel $hotel, string $room, string $inspection, RoomReadinessService $service): JsonResponse
    {
        $data = $request->validate([
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        if (trim($data['note']) === '') {
            throw ValidationException::withMessages(['note' => ['A meaningful review note is required.']]);
        }
        return response()->json(['data' => $service->review(
            $hotel, (int) $room, (int) $inspection, (int) $request->user()->id, $data
        )]);
    }

    public function history(Request $request, Hotel $hotel, string $room, RoomReadinessService $service): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return response()->json($service->history($hotel, (int) $room, (int) ($data['page'] ?? 1)));
    }
}

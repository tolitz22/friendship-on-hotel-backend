<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Keycards\KeycardAuthorizationService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class KeycardAuthorizationController
{
    public function index(Request $request, Hotel $hotel, KeycardAuthorizationService $service): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:pending_manager,authorized_mock,rejected,revoked'],
            'bookingId' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($service->listing(
            $hotel, (int) $request->user()->id, $this->manager($request, $hotel), $filters
        ));
    }

    public function history(Request $request, Hotel $hotel, string $authorization, KeycardAuthorizationService $service): JsonResponse
    {
        return response()->json(['data' => $service->history(
            $hotel, (int) $request->user()->id, $this->manager($request, $hotel), (int) $authorization
        )]);
    }

    public function store(Request $request, Hotel $hotel, KeycardAuthorizationService $service): JsonResponse
    {
        $data = $request->validate([
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:100'],
            'kind' => ['required', 'in:initial,replacement,emergency_access'],
            'bookingId' => ['required_unless:kind,emergency_access', 'prohibited_if:kind,emergency_access', 'integer', 'min:1'],
            'roomId' => ['required_if:kind,emergency_access', 'prohibited_unless:kind,emergency_access', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\\S/u'],
        ]);
        return response()->json(['data' => $service->request(
            $hotel, (int) $request->user()->id, $data
        )], 201);
    }

    public function review(Request $request, Hotel $hotel, string $authorization, KeycardAuthorizationService $service): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\\S/u'],
        ]);
        return response()->json(['data' => $service->review(
            $hotel, (int) $request->user()->id, (int) $authorization, $data
        )]);
    }

    public function revoke(Request $request, Hotel $hotel, string $authorization, KeycardAuthorizationService $service): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\\S/u']]);
        return response()->json(['data' => $service->revoke(
            $hotel, (int) $request->user()->id, (int) $authorization, $data['note']
        )]);
    }

    private function manager(Request $request, Hotel $hotel): bool
    {
        return $request->user()->tokenCan('hotel:write')
            && $request->user()->memberships()->where('hotel_id', $hotel->id)
                ->where('role', 'admin')->where('is_active', true)->exists();
    }
}

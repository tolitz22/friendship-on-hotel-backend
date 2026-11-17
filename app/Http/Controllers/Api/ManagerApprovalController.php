<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ManagerApprovalService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ManagerApprovalController
{
    public function index(Request $request, Hotel $hotel, ManagerApprovalService $service): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'rejected', 'executed'])],
            'bookingId' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return response()->json($service->list(
            $hotel, (int) $request->user()->id, $this->isManager($request, $hotel), $filters
        ));
    }

    public function store(Request $request, Hotel $hotel, string $booking, ManagerApprovalService $service): JsonResponse
    {
        $input = $request->validate([
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:100'],
            'action' => ['required', Rule::in(ManagerApprovalService::ACTIONS)],
            'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\\S/u'],
            'checkIn' => ['required_if:action,amend_dates', 'prohibited_unless:action,amend_dates', 'date_format:Y-m-d'],
            'checkOut' => ['required_if:action,amend_dates', 'prohibited_unless:action,amend_dates',
                'date_format:Y-m-d', 'after:checkIn'],
        ]);
        return response()->json(['data' => $service->request(
            $hotel, (int) $request->user()->id, (int) $booking, $input
        )], 201);
    }

    public function review(Request $request, Hotel $hotel, string $approval, ManagerApprovalService $service): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000'],
        ]);
        if ($input['decision'] === 'reject' && trim((string) ($input['note'] ?? '')) === '') {
            return response()->json(['message' => 'A rejection reason is required.'], 422);
        }
        return response()->json(['data' => $service->review(
            $hotel, (int) $request->user()->id, (int) $approval,
            $input['decision'], $input['note'] ?? null
        )]);
    }

    public function history(Request $request, Hotel $hotel, string $approval, ManagerApprovalService $service): JsonResponse
    {
        return response()->json(['data' => $service->history(
            $hotel, (int) $request->user()->id, $this->isManager($request, $hotel), (int) $approval
        )]);
    }

    private function isManager(Request $request, Hotel $hotel): bool
    {
        return $request->user()->tokenCan('hotel:write')
            && $request->user()->memberships()->where('hotel_id', $hotel->id)
                ->where('is_active', true)->where('role', 'admin')->exists();
    }
}

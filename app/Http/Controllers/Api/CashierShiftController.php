<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\CashierShiftService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CashierShiftController
{
    public function index(Request $request, Hotel $hotel, CashierShiftService $service): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:open,submitted,reconciled,flagged'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($service->listing($hotel, (int) $request->user()->id,
            $this->manager($request, $hotel), $filters));
    }

    public function current(Request $request, Hotel $hotel, CashierShiftService $service): JsonResponse
    {
        // Always return the current cashier's drawer, even for a manager.
        // Never choose an unrelated employee's shift from a hotel-wide list.
        $result = $service->listing($hotel, (int) $request->user()->id, false,
            ['status' => 'open', 'per_page' => 1]);
        return response()->json(['data' => $result['data'][0] ?? null]);
    }

    public function show(Request $request, Hotel $hotel, string $shift, CashierShiftService $service): JsonResponse
    {
        return response()->json(['data' => $service->details($hotel, (int) $request->user()->id,
            $this->manager($request, $hotel), (int) $shift)]);
    }

    public function history(Request $request, Hotel $hotel, string $shift, CashierShiftService $service): JsonResponse
    {
        return response()->json(['data' => $service->history($hotel, (int) $request->user()->id,
            $this->manager($request, $hotel), (int) $shift)]);
    }

    public function open(Request $request, Hotel $hotel, CashierShiftService $service): JsonResponse
    {
        $data = $request->validate(['openingFloatMinor' => ['required', 'integer', 'min:0', 'max:100000000000']]);
        return response()->json(['data' => $service->open(
            $hotel, (int) $request->user()->id, (int) $data['openingFloatMinor']
        )], 201);
    }

    public function receive(Request $request, Hotel $hotel, string $shift, string $booking, CashierShiftService $service): JsonResponse
    {
        $data = $request->validate([
            'requestKey' => ['required','string','min:8','max:100'],
            'reference' => ['required','string','min:3','max:120','regex:/\\S/u'],
            'amountMinor' => ['required','integer','min:1','max:100000000000'],
            'duplicateOverrideReason' => ['sometimes','string','min:12','max:500'],
        ]);
        $data['duplicateOverrideAuthorized'] = $this->manager($request, $hotel);
        $result = $service->receive($hotel, (int) $request->user()->id,
            (int) $shift, (int) $booking, $data);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function close(Request $request, Hotel $hotel, string $shift, CashierShiftService $service): JsonResponse
    {
        $data = $request->validate([
            'countedCashMinor' => ['required','integer','min:0','max:100000000000'],
            'note' => ['sometimes','nullable','string','max:1000'],
        ]);
        return response()->json(['data' => $service->close($hotel, (int) $request->user()->id,
            (int) $shift, (int) $data['countedCashMinor'], $data['note'] ?? null)]);
    }

    public function reconcile(Request $request, Hotel $hotel, string $shift, CashierShiftService $service): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required','in:reconcile,flag'],
            'note' => ['required','string','min:5','max:1000','regex:/\\S/u'],
        ]);
        return response()->json(['data' => $service->reconcile($hotel, (int) $request->user()->id,
            (int) $shift, $data['decision'], $data['note'])]);
    }

    private function manager(Request $request, Hotel $hotel): bool
    {
        return $request->user()->tokenCan('hotel:write')
            && $request->user()->memberships()->where('hotel_id', $hotel->id)
                ->where('role','admin')->where('is_active', true)->exists();
    }
}

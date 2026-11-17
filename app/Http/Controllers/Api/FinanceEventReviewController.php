<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FinanceEventReviewService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

final class FinanceEventReviewController
{
    public function inbox(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'status' => ['sometimes', 'in:unmapped,reference_matched'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = DB::table('finance_gateway_events')->where('hotel_id', $hotel->id);
        if (isset($input['status'])) {
            $query->where('processing_status', $input['status']);
        }
        $page = $query->orderByDesc('id')->paginate((int) ($input['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(function (object $event): array {
                $payload = json_decode($event->payload_json, true, flags: JSON_THROW_ON_ERROR);
                return [
                    'id' => (string) $event->id,
                    'providerEventId' => $event->event_id,
                    'eventType' => $event->event_type,
                    'reference' => $payload['reference'],
                    'amountMinor' => (int) $payload['amountMinor'],
                    'currency' => $payload['currency'],
                    'status' => $event->processing_status,
                    'verifiedTransportOnly' => true,
                    'bankOrGatewayVerified' => false,
                ];
            })->values(),
            'meta' => [
                'page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage(),
            ],
        ]);
    }

    public function index(Hotel $hotel, string $booking, FinanceEventReviewService $service): JsonResponse
    {
        return response()->json(['data' => $service->index($hotel, (int) $booking)]);
    }

    public function match(Request $request, Hotel $hotel, string $booking, FinanceEventReviewService $service): JsonResponse
    {
        $input = $request->validate([
            'eventId' => ['required', 'integer', 'min:1'],
            'paymentRecordId' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if (trim($input['note']) === '') {
            throw ValidationException::withMessages(['note' => ['A review note is required.']]);
        }
        return response()->json(['data' => $service->match(
            $hotel, (int) $booking, (int) $request->user()->id, $input
        )], 201);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\PayMongoSandboxWebhook;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PayMongoSandboxController
{
    public function webhook(Request $request, PayMongoSandboxWebhook $service): JsonResponse
    {
        $result = $service->receive($request);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 202);
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = DB::table('paymongo_sandbox_events')
            ->where('hotel_id', $hotel->id)
            ->orderByDesc('id')
            ->paginate((int) ($input['per_page'] ?? 20));
        return response()->json([
            'data' => $page->getCollection()->map(fn (object $event): array => [
                'id' => (string) $event->id,
                'provider' => 'paymongo',
                'mode' => 'test',
                'providerEventId' => $event->event_id,
                'eventType' => $event->event_type,
                'resourceId' => $event->resource_id,
                'reference' => $event->reference,
                'status' => $event->processing_status,
                'bankOrGatewayVerified' => false,
                'isSettled' => false,
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }
}

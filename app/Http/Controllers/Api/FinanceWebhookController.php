<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\SignedFinanceEventInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinanceWebhookController
{
    public function __invoke(Request $request, SignedFinanceEventInbox $inbox): JsonResponse
    {
        $result = $inbox->ingest($request);

        return response()->json(['data' => $result], $result['replayed'] ? 200 : 202);
    }
}

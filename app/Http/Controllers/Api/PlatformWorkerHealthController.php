<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\PlatformWorkerHealth;
use Illuminate\Http\JsonResponse;

final class PlatformWorkerHealthController
{
    public function __invoke(PlatformWorkerHealth $health): JsonResponse
    {
        return response()->json(['data' => $health->snapshot()])->header('Cache-Control', 'no-store, private');
    }
}

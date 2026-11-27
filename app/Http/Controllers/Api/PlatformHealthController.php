<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\PlatformHealth;
use Illuminate\Http\JsonResponse;

final class PlatformHealthController
{
    public function __invoke(PlatformHealth $health): JsonResponse
    {
        return response()->json(['data' => $health->snapshot()])->header('Cache-Control', 'no-store, private');
    }
}

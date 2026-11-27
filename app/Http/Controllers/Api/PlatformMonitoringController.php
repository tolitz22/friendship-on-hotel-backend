<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\PlatformMonitoring;
use App\Logging\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformMonitoringController
{
    public function __invoke(Request $request, PlatformMonitoring $monitoring): JsonResponse
    {
        $input = $request->validate([
            'minutes' => ['sometimes', 'integer', 'in:15,60,360,1440'],
            'environment' => ['sometimes', 'in:all,local,testing,staging,production,other'],
            'role' => ['sometimes', 'in:all,backend,worker,scheduler,unspecified'],
            'requestId' => ['nullable', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D'],
        ]);
        SecurityLog::record('pms.security.platform.monitoring_viewed', ['actor_id' => (int) $request->user()->id]);

        return response()->json(['data' => $monitoring->snapshot((int) ($input['minutes'] ?? 60), $input['environment'] ?? 'all', $input['role'] ?? 'all', $input['requestId'] ?? null)])
            ->header('Cache-Control', 'no-store, private');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\FrontendErrorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class FrontendErrorController
{
    public function __invoke(Request $request): Response
    {
        abort_if(strlen($request->getContent()) > 2048, 413);
        $input = $request->validate([
            'kind' => ['required', Rule::in(FrontendErrorLog::KINDS)],
            'screen' => ['required', Rule::in(FrontendErrorLog::SCREENS)],
            'error_type' => ['sometimes', Rule::in(FrontendErrorLog::ERROR_TYPES)],
            'status_code' => ['sometimes', 'integer', 'between:0,599'],
            'related_request_id' => ['sometimes', 'string', 'regex:'.FrontendErrorLog::REQUEST_ID_PATTERN],
        ]);
        if (isset($input['status_code'])) {
            $input['status_code'] = (int) $input['status_code'];
        }

        // The reporter identity comes from authenticated Sanctum, not the browser.
        // Related request IDs are untrusted diagnostic hints, not authorization.
        try {
            Log::warning(FrontendErrorLog::EVENT, FrontendErrorLog::metadata($input) + [
                'user_id' => (int) $request->user()->id,
            ]);
        } catch (Throwable) {
            // Best-effort technical telemetry must not affect hotel workflows.
        }

        return response()->noContent();
    }
}

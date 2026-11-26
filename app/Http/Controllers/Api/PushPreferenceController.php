<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\PushPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PushPreferenceController
{
    public function show(Request $request, Hotel $hotel, PushPreferences $preferences): JsonResponse
    {
        return response()->json(['data' => $preferences->get($hotel->id, $request->user()->id)]);
    }

    public function update(Request $request, Hotel $hotel, PushPreferences $preferences): JsonResponse
    {
        $rules = array_fill_keys(array_merge(['enabled'], PushPreferences::KINDS), ['required', 'boolean']);
        $values = $request->validate($rules);

        return response()->json(['data' => $preferences->put($hotel->id, $request->user()->id, $values)]);
    }
}

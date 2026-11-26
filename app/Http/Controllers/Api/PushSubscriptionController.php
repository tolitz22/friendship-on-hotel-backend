<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PushSubscriptionController
{
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        return response()->json(['data' => $this->scope($request, $hotel)->orderByDesc('updated_at')->get()->map(fn ($row) => $this->resource($row))]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        abort_if($request->user()->must_change_password, 403);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'size:87'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'size:22'],
            'deviceName' => ['required', 'string', 'max:100'],
        ]);
        $url = parse_url($data['endpoint']);
        $host = strtolower($url['host'] ?? '');
        $allowed = in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'], true)
            || str_ends_with($host, '.push.apple.com')
            || str_ends_with($host, '.notify.windows.com');
        if (! $allowed || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443)) {
            throw ValidationException::withMessages(['endpoint' => ['Unsupported push service endpoint.']]);
        }
        $hash = hash('sha256', $data['endpoint']);
        if (PushSubscription::query()->where('endpoint_hash', $hash)->where('user_id', '!=', $request->user()->id)->exists()) {
            throw ValidationException::withMessages(['endpoint' => ['This browser is registered to another account. Reset browser push permissions before registering.']]);
        }
        $row = PushSubscription::updateOrCreate([
            'hotel_id' => $hotel->id,
            'user_id' => $request->user()->id,
            'endpoint_hash' => $hash,
        ], [
            'endpoint' => $data['endpoint'],
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'device_name' => $data['deviceName'],
        ]);
        return response()->json(['data' => $this->resource($row)]);
    }

    public function destroy(Request $request, Hotel $hotel, string $subscription): JsonResponse
    {
        $this->scope($request, $hotel)->whereKey($subscription)->firstOrFail()->delete();
        return response()->json(['message' => 'Device removed.']);
    }

    private function scope(Request $request, Hotel $hotel): \Illuminate\Database\Eloquent\Builder
    {
        return PushSubscription::query()->where('hotel_id', $hotel->id)->where('user_id', $request->user()->id);
    }

    private function resource(PushSubscription $row): array
    {
        return ['id' => (string) $row->id, 'deviceName' => $row->device_name, 'updatedAt' => $row->updated_at->toISOString()];
    }
}

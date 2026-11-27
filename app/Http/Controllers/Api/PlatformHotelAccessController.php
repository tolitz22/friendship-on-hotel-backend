<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\SecurityLog;
use App\Models\Hotel;
use App\Services\PlatformHotelAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformHotelAccessController
{
    public function show(Request $request, int $hotel): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        $property = Hotel::findOrFail($hotel);
        $page = DB::table('platform_hotel_access_changes')->where('hotel_id', $hotel)->orderByDesc('revision')->paginate(20);

        return $this->response(['data' => [
            'state' => PlatformHotelAccess::state($property), 'revision' => (int) $property->platform_access_revision,
            'blockers' => PlatformHotelAccess::blockers($hotel),
            'history' => $page->getCollection()->map(static fn ($row): array => [
                'revision' => (int) $row->revision, 'from' => $row->from_state, 'to' => $row->to_state,
                'actorId' => $row->actor_id === null ? null : (string) $row->actor_id, 'reason' => $row->reason,
                'createdAt' => \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(),
            ])->values(),
        ], 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function update(Request $request, int $hotel): JsonResponse
    {
        $input = $request->validate([
            'state' => ['required', 'in:active,restricted,suspended'],
            'revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirmed' => ['accepted'],
            'clearanceConfirmed' => ['exclude_unless:state,suspended', 'required', 'accepted'],
        ]);
        $property = DB::transaction(function () use ($hotel, $request, $input): Hotel {
            $property = PlatformHotelAccess::lock($hotel);
            $previous = PlatformHotelAccess::state($property);
            abort_unless((int) $property->platform_access_revision === (int) $input['revision'], 409, 'Access state changed. Refresh before continuing.');
            if ($previous === $input['state']) {
                return $property;
            }
            if ($input['state'] === 'suspended') {
                if ($previous !== 'restricted') {
                    throw ValidationException::withMessages(['state' => ['Restrict new bookings before suspending the property.']]);
                }
                $blockers = PlatformHotelAccess::blockers($hotel);
                if (array_sum($blockers) > 0) {
                    throw ValidationException::withMessages(['state' => ['Suspension blocked: clear outstanding reservations, active group holds and unreconciled cashier shifts first.']]);
                }
            }
            $property->platform_access_state = $input['state'];
            $property->platform_access_revision = (int) $property->platform_access_revision + 1;
            $property->save();
            DB::table('platform_hotel_access_changes')->insert([
                'hotel_id' => $hotel, 'actor_id' => $request->user()->id, 'revision' => $property->platform_access_revision,
                'from_state' => $previous, 'to_state' => $input['state'], 'reason' => trim($input['reason']), 'created_at' => now(),
            ]);

            return $property;
        }, 3);
        SecurityLog::record('pms.security.platform.hotel_access_changed', ['actor_id' => (int) $request->user()->id, 'hotel_id' => $hotel]);

        return $this->response(['data' => ['state' => PlatformHotelAccess::state($property), 'revision' => (int) $property->platform_access_revision]]);
    }

    private function response(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}

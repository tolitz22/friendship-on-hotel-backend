<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HotelController
{
    public function index(Request $request): JsonResponse
    {
        $hotels = $request->user()->memberships()
            ->where('is_active', true)
            ->with('hotel:id,name,timezone,currency')
            ->get()
            ->map(fn ($membership): array => [
                'id' => (string) $membership->hotel_id,
                'name' => $membership->hotel->name,
                'timeZone' => $membership->hotel->timezone,
                'currency' => $membership->hotel->currency,
                'role' => $membership->role,
            ])->values();

        return response()->json(['data' => $hotels]);
    }

    public function update(Request $request, Hotel $hotel): JsonResponse
    {
        $attributes = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'contactEmail' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contactPhone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ])->validate();

        DB::transaction(function () use ($request, $hotel, $attributes): void {
            $locked = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            $before = [
                'name' => $locked->name,
                'address' => $locked->address,
                'contactEmail' => $locked->contact_email,
                'contactPhone' => $locked->contact_phone,
            ];
            $values = [];
            foreach (['name', 'address', 'contactEmail', 'contactPhone'] as $field) {
                if (!array_key_exists($field, $attributes)) continue;
                $value = is_string($attributes[$field]) ? trim($attributes[$field]) : null;
                if ($field === 'name' && ($value === null || strlen($value) < 2)) {
                    throw ValidationException::withMessages(['name' => ['Enter a hotel name with at least two characters.']]);
                }
                $values[$field] = $value === '' ? null : $value;
            }
            $after = [...$before, ...$values];
            if ($before === $after) return;

            $locked->name = $after['name'];
            $locked->address = $after['address'];
            $locked->contact_email = $after['contactEmail'];
            $locked->contact_phone = $after['contactPhone'];
            $locked->save();

            DB::table('catalog_audit_logs')->insert([
                'hotel_id' => $hotel->id,
                'actor_id' => $request->user()->id,
                'subject_type' => 'hotel',
                'subject_id' => $hotel->id,
                'changes_before' => json_encode($before, JSON_THROW_ON_ERROR),
                'changes_after' => json_encode($after, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }, 3);

        return $this->show($hotel->refresh());
    }

    public function show(Hotel $hotel): JsonResponse
    {
        return response()->json(['data' => [
            'id' => (string) $hotel->id,
            'name' => $hotel->name,
            'timeZone' => $hotel->timezone,
            'currency' => $hotel->currency,
            'address' => $hotel->address,
            'contactEmail' => $hotel->contact_email,
            'contactPhone' => $hotel->contact_phone,
        ]]);
    }
}

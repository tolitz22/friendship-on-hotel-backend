<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

        $hotel->fill([
            ...array_intersect_key($attributes, ['name' => true, 'address' => true]),
        ]);
        if (array_key_exists('contactEmail', $attributes)) {
            $hotel->contact_email = $attributes['contactEmail'];
        }
        if (array_key_exists('contactPhone', $attributes)) {
            $hotel->contact_phone = $attributes['contactPhone'];
        }
        $hotel->save();

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

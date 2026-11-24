<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\RateBookingPolicyService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class RateBookingPolicyController
{
    public function index(Hotel $hotel, string $plan, RateBookingPolicyService $service): JsonResponse
    {
        $service->plan($hotel, (int) $plan);
        return response()->json(['data' => $service->versions($hotel, (int) $plan)->orderByDesc('version')->paginate(20)]);
    }

    public function store(Request $request, Hotel $hotel, string $plan, RateBookingPolicyService $service): JsonResponse
    {
        $input = $request->validate([
            'expectedVersion' => ['required', 'integer', 'min:0'],
            'depositKind' => ['required', 'in:none,fixed,first_night,percent,full'],
            'depositValue' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'freeCancelDays' => ['required', 'integer', 'min:0', 'max:365'],
            'lateCancelKind' => ['required', 'in:none,fixed,first_night,percent,full'],
            'lateCancelValue' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'noShowKind' => ['required', 'in:none,fixed,first_night,percent,full'],
            'noShowValue' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        foreach (['deposit', 'lateCancel', 'noShow'] as $prefix) {
            $kind = $input[$prefix.'Kind'];
            $value = (int) $input[$prefix.'Value'];
            if (($kind === 'percent' && ($value < 1 || $value > 10000))
                || ($kind === 'fixed' && $value < 1)
                || (! in_array($kind, ['fixed', 'percent'], true) && $value !== 0)) {
                throw ValidationException::withMessages([$prefix.'Value' => ['Invalid amount for the selected calculation method.']]);
            }
        }
        return response()->json(['data' => $service->save($hotel, (int) $request->user()->id, (int) $plan, $input)], 201);
    }

    public function preview(Request $request, Hotel $hotel, string $plan, RateBookingPolicyService $service): JsonResponse
    {
        $input = $request->validate([
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
            'cancellationDate' => ['required', 'date_format:Y-m-d', 'before:checkOut'],
            'corporateCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'promoCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if (isset($input['corporateCode'], $input['promoCode'])) {
            throw ValidationException::withMessages(['promoCode' => ['Select only one eligibility code.']]);
        }
        return response()->json(['data' => $service->preview($hotel, (int) $plan, $input)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\SeasonalRateService;
use App\Domain\Reservations\StayPeriod;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SeasonalRateController
{
    public function index(Hotel $hotel, string $plan, SeasonalRateService $rates): JsonResponse
    {
        abort_unless(DB::table('room_rate_plans')->where('hotel_id', $hotel->id)->where('id', $plan)->exists(), 404);
        return response()->json(['data' => $rates->rules($hotel, (int) $plan)->orderByRaw('CASE WHEN retired_at IS NULL THEN 0 ELSE 1 END')->orderBy('starts_on')->orderBy('id')->paginate(50)]);
    }

    private function validatedRule(Request $request): array
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', 'in:seasonal,date_override,weekend,holiday,corporate,promotion'],
            'startsOn' => ['required', 'date_format:Y-m-d'],
            'endsOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:startsOn'],
            'weekendDays' => ['required_if:kind,weekend', 'exclude_unless:kind,weekend', 'array', 'min:1', 'max:7'],
            'weekendDays.*' => ['integer', 'distinct', 'between:1,7'],
            'eligibilityCode' => ['required_if:kind,corporate,promotion', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'minimumNights' => ['nullable', 'integer', 'min:1', 'max:31'],
            'nightlyMinor' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ]);
        if ($input['kind'] === 'date_override' && $input['startsOn'] !== $input['endsOn']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['endsOn' => ['A date override applies to a single night.']]);
        }
        if ($input['kind'] === 'weekend') {
            $input['weekendDays'] = array_map('intval', $input['weekendDays']);
            sort($input['weekendDays']);
        }
        if (in_array($input['kind'], ['corporate', 'promotion'], true)) {
            $input['eligibilityCode'] = strtoupper($input['eligibilityCode']);
            $input['minimumNights'] = (int) ($input['minimumNights'] ?? 1);
        } else {
            unset($input['eligibilityCode'], $input['minimumNights']);
        }
        return $input;
    }

    public function store(Request $request, Hotel $hotel, string $plan, SeasonalRateService $rates): JsonResponse
    {
        $input = $this->validatedRule($request);
        return response()->json(['data' => ['id' => (string) $rates->create($hotel, (int) $request->user()->id, (int) $plan, $input)]], 201);
    }

    public function update(Request $request, Hotel $hotel, string $plan, string $rule, SeasonalRateService $rates): JsonResponse
    {
        $id = $rates->replace($hotel, (int) $request->user()->id, (int) $plan, (int) $rule, $this->validatedRule($request));
        return response()->json(['data' => ['id' => (string) $id]]);
    }

    public function retire(Request $request, Hotel $hotel, string $plan, string $rule, SeasonalRateService $rates): JsonResponse
    {
        $rates->replace($hotel, (int) $request->user()->id, (int) $plan, (int) $rule, null);
        return response()->json(['data' => ['id' => $rule, 'retired' => true]]);
    }

    public function bulk(Request $request, Hotel $hotel, string $plan, SeasonalRateService $rates): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'startsOn' => ['required', 'date_format:Y-m-d'],
            'endsOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:startsOn'],
            'nightlyMinor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'replaceExisting' => ['required', 'boolean'],
            'expectedRuleIds' => ['present', 'array', 'max:31'],
            'expectedRuleIds.*' => ['integer', 'min:1', 'distinct'],
        ]);
        return response()->json(['data' => $rates->bulkOverrides($hotel, (int) $request->user()->id, (int) $plan, $input)], 201);
    }

    public function preview(Request $request, Hotel $hotel, string $plan, SeasonalRateService $rates): JsonResponse
    {
        $input = $request->validate([
            'roomTypeId' => ['required', 'integer', 'min:1'],
            'corporateCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'promoCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d', 'after:checkIn'],
        ]);
        if (isset($input['corporateCode'], $input['promoCode'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['promoCode' => ['Select only one negotiated or promotional rate.']]);
        }
        return response()->json(['data' => $rates->preview($hotel, (int) $input['roomTypeId'], (int) $plan,
            new StayPeriod($input['checkIn'], $input['checkOut']),
            isset($input['corporateCode']) ? strtoupper($input['corporateCode']) : null,
            isset($input['promoCode']) ? strtoupper($input['promoCode']) : null)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\SecurityLog;
use App\Models\Hotel;
use App\Models\PlatformHotelSubscription;
use App\Models\PlatformSubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformHotelSubscriptionController
{
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today('Asia/Manila');
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'status' => ['sometimes', 'in:all,unassigned,trial,active,expired']]);
        $today = $this->today();
        $query = Hotel::query()->select(['id', 'name', 'currency'])->with('platformSubscription')->withCount(['rooms', 'memberships as capacity_staff_count' => fn ($q) => $q->where('is_active', true)]);
        $search = trim($input['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
        }
        $status = $input['status'] ?? 'all';
        if ($status === 'unassigned') {
            $query->whereDoesntHave('platformSubscription');
        } elseif ($status === 'expired') {
            $query->whereHas('platformSubscription', fn ($q) => $q->whereDate('expires_on', '<=', $today->toDateString()));
        } elseif (in_array($status, ['trial', 'active'], true)) {
            $query->whereHas('platformSubscription', fn ($q) => $q->where('kind', $status === 'trial' ? 'trial' : 'paid')
                ->whereDate('expires_on', '>', $today->toDateString()));
        }
        $page = $query->orderByDesc('id')->paginate(20);
        $issued = DB::table('platform_subscription_invoices as invoice')->join('platform_hotel_subscriptions as agreement', function ($join): void {
            $join->on('agreement.id', '=', 'invoice.subscription_id')->on('agreement.revision', '=', 'invoice.agreement_revision');
        })->whereIn('invoice.hotel_id', $page->getCollection()->pluck('id'))
            ->select('invoice.subscription_id', 'invoice.agreement_revision')->get()->mapWithKeys(fn ($row) => [$row->subscription_id.':'.$row->agreement_revision => true]);

        return $this->response(['data' => $page->getCollection()->map(fn (Hotel $hotel): array => [
            'hotelId' => (string) $hotel->id, 'hotelName' => $hotel->name, 'currency' => $hotel->currency,
            'usage' => app(\App\Services\PlatformSubscriptionCapacity::class)->snapshot($hotel),
            'currentRevisionBilled' => $hotel->platformSubscription !== null && $issued->has($hotel->platformSubscription->id.':'.$hotel->platformSubscription->revision),
            'subscription' => $hotel->platformSubscription ? $this->project($hotel->platformSubscription, $today) : null,
        ])->values(), 'today' => $today->toDateString(), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function update(Request $request, int $hotel): JsonResponse
    {
        $input = $request->validate([
            'action' => ['required', 'in:trial,assign,renew'],
            'revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'planId' => ['required_unless:action,renew', 'prohibited_if:action,renew', 'integer', 'min:1'],
            'planRevision' => ['required_unless:action,renew', 'prohibited_if:action,renew', 'integer', 'min:1'],
            'cycle' => ['required_unless:action,renew', 'prohibited_if:action,renew', 'in:monthly,annual'],
            'trialDays' => ['required_if:action,trial', 'prohibited_unless:action,trial', 'integer', 'min:1', 'max:90'],
        ]);
        $today = $this->today();
        $subscription = DB::transaction(function () use ($request, $hotel, $input, $today): PlatformHotelSubscription {
            $property = Hotel::query()->lockForUpdate()->findOrFail($hotel);
            $current = PlatformHotelSubscription::where('hotel_id', $hotel)->lockForUpdate()->first();
            abort_unless(($current?->revision ?? 0) === (int) $input['revision'], 409, 'Subscription changed. Refresh before continuing.');
            if ($input['action'] === 'renew') {
                if (! $current || $current->kind !== 'paid') {
                    throw ValidationException::withMessages(['action' => ['Only a paid subscription agreement can be renewed.']]);
                }
                $end = CarbonImmutable::parse($current->expires_on->toDateString(), 'Asia/Manila')->startOfDay();
                $anchor = $end->greaterThan($today) ? $end : $today;
                if ($end->lessThanOrEqualTo($today)) {
                    $current->starts_on = $today->toDateString();
                }
                $current->expires_on = $this->end($anchor, $current->cycle)->toDateString();
            } else {
                $live = $current && $current->expires_on->toDateString() > $today->toDateString();
                if ($live && ($current->kind === 'paid' || $input['action'] === 'trial')) {
                    throw ValidationException::withMessages(['action' => ['An unexpired agreement exists. Renew it or wait for expiry.']]);
                }
                if ($input['action'] === 'trial' && ($current !== null)) {
                    throw ValidationException::withMessages(['action' => ['A trial is available only before the first agreement.']]);
                }
                $plan = PlatformSubscriptionPlan::query()->lockForUpdate()->findOrFail($input['planId']);
                abort_unless($plan->revision === (int) $input['planRevision'], 409, 'Plan changed. Refresh before accepting the new terms.');
                if (! $plan->is_active || $property->currency !== $plan->currency) {
                    throw ValidationException::withMessages(['planId' => ['Choose an active plan in the property currency.']]);
                }
                $trial = $input['action'] === 'trial';
                $current ??= new PlatformHotelSubscription(['hotel_id' => $hotel, 'revision' => 0, 'trial_used' => false]);
                $current->fill([
                    'plan_id' => $plan->id, 'plan_revision' => $plan->revision, 'plan_name' => $plan->name, 'currency' => $plan->currency,
                    'cycle' => $input['cycle'], 'price_minor' => $input['cycle'] === 'monthly' ? $plan->monthly_minor : $plan->annual_minor,
                    'room_limit' => $plan->room_limit, 'staff_limit' => $plan->staff_limit,
                    'kind' => $trial ? 'trial' : 'paid', 'starts_on' => $today->toDateString(),
                    'expires_on' => ($trial ? $today->addDays((int) $input['trialDays']) : $this->end($today, $input['cycle']))->toDateString(),
                    'trial_used' => $current->trial_used || $trial,
                ]);
            }
            $current->revision++;
            $current->save();
            DB::table('platform_hotel_subscription_changes')->insert([
                'subscription_id' => $current->id, 'actor_id' => $request->user()->id, 'revision' => $current->revision,
                'action' => $input['action'], 'snapshot' => json_encode($this->project($current, $today), JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $current;
        }, 3);
        SecurityLog::record('pms.security.platform.subscription_changed', ['actor_id' => (int) $request->user()->id, 'hotel_id' => $hotel]);

        return $this->response(['data' => $this->project($subscription, $today)]);
    }

    public function history(Request $request, int $hotel): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        Hotel::findOrFail($hotel);
        $subscription = PlatformHotelSubscription::where('hotel_id', $hotel)->firstOrFail();
        $page = DB::table('platform_hotel_subscription_changes')->where('subscription_id', $subscription->id)->orderByDesc('revision')->paginate(20);

        return $this->response(['data' => $page->getCollection()->map(static fn ($row): array => [
            'revision' => (int) $row->revision, 'action' => $row->action, 'actorId' => $row->actor_id === null ? null : (string) $row->actor_id,
            'createdAt' => \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(),
            'snapshot' => json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR),
        ])->values(), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    private function end(CarbonImmutable $start, string $cycle): CarbonImmutable
    {
        return $cycle === 'annual' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
    }

    private function project(PlatformHotelSubscription $s, CarbonImmutable $today): array
    {
        $expiry = $s->expires_on->toDateString();
        return ['id' => (string) $s->id, 'planId' => (string) $s->plan_id, 'planRevision' => $s->plan_revision,
            'planName' => $s->plan_name, 'currency' => $s->currency, 'cycle' => $s->cycle, 'priceMinor' => $s->price_minor,
            'roomLimit' => $s->room_limit, 'staffLimit' => $s->staff_limit, 'kind' => $s->kind,
            'startsOn' => $s->starts_on->toDateString(), 'expiresOn' => $expiry,
            'status' => $expiry <= $today->toDateString() ? 'expired' : ($s->kind === 'trial' ? 'trial' : 'active'),
            'trialUsed' => $s->trial_used, 'revision' => $s->revision];
    }

    private function response(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}

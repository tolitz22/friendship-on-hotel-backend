<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\SecurityLog;
use App\Models\PlatformSubscriptionPlan;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PlatformSubscriptionPlanController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'status' => ['sometimes', 'in:all,active,inactive']]);
        $query = PlatformSubscriptionPlan::query();
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }
        $page = $query->orderByDesc('is_active')->orderBy('id')->paginate(20);

        return $this->response(['data' => $page->getCollection()->map(fn ($plan) => $this->project($plan))->values(),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request);
        try {
            $plan = DB::transaction(function () use ($request, $input): PlatformSubscriptionPlan {
                $plan = PlatformSubscriptionPlan::create([...$this->attributes($input), 'code' => $input['code'], 'currency' => 'PHP', 'revision' => 1]);
                $this->record($plan, (int) $request->user()->id);

                return $plan;
            }, 3);
        } catch (QueryException $error) {
            if (PlatformSubscriptionPlan::where('code', $input['code'])->exists()) {
                throw ValidationException::withMessages(['code' => ['This plan code already exists.']]);
            }
            throw $error;
        }
        SecurityLog::record('pms.security.platform.plan_created', ['actor_id' => (int) $request->user()->id]);

        return $this->response(['data' => $this->project($plan)], 201);
    }

    public function update(Request $request, int $plan): JsonResponse
    {
        $input = $this->validated($request, true);
        $updated = DB::transaction(function () use ($request, $plan, $input): PlatformSubscriptionPlan {
            $model = PlatformSubscriptionPlan::query()->lockForUpdate()->findOrFail($plan);
            abort_unless($model->revision === (int) $input['revision'], 409, 'This plan has changed. Reload the catalog before editing again.');
            $model->fill($this->attributes($input));
            if ($model->isDirty()) {
                $model->revision++;
                $model->save();
                $this->record($model, (int) $request->user()->id);
            }

            return $model;
        }, 3);
        SecurityLog::record('pms.security.platform.plan_updated', ['actor_id' => (int) $request->user()->id]);

        return $this->response(['data' => $this->project($updated)]);
    }

    public function history(Request $request, int $plan): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        PlatformSubscriptionPlan::findOrFail($plan);
        $page = DB::table('platform_subscription_plan_changes')->where('plan_id', $plan)->orderByDesc('revision')->paginate(20);

        return $this->response(['data' => $page->getCollection()->map(static fn ($row): array => [
            'revision' => (int) $row->revision, 'actorId' => $row->actor_id === null ? null : (string) $row->actor_id,
            'createdAt' => \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(),
            'snapshot' => json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR),
        ])->values(), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    private function validated(Request $request, bool $editing = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'monthlyMinor' => ['required', 'integer', 'min:0', 'max:999999999'],
            'annualMinor' => ['required', 'integer', 'min:0', 'max:999999999'],
            'roomLimit' => ['present', 'nullable', 'integer', 'min:1', 'max:100000'],
            'staffLimit' => ['present', 'nullable', 'integer', 'min:1', 'max:100000'],
            'isActive' => ['required', 'boolean'],
        ];
        $rules['code'] = $editing ? ['prohibited'] : ['required', 'string', 'regex:/\A[a-z][a-z0-9-]{1,39}\z/D', Rule::unique('platform_subscription_plans', 'code')];
        $rules['revision'] = $editing ? ['required', 'integer', 'min:1', 'max:2147483646'] : ['prohibited'];

        return $request->validate($rules);
    }

    private function attributes(array $input): array
    {
        return ['name' => trim($input['name']), 'description' => $input['description'] ?? null,
            'monthly_minor' => (int) $input['monthlyMinor'], 'annual_minor' => (int) $input['annualMinor'],
            'room_limit' => $input['roomLimit'] === null ? null : (int) $input['roomLimit'],
            'staff_limit' => $input['staffLimit'] === null ? null : (int) $input['staffLimit'],
            'is_active' => (bool) $input['isActive']];
    }

    private function project(PlatformSubscriptionPlan $plan): array
    {
        return ['id' => (string) $plan->id, 'name' => $plan->name, 'code' => $plan->code, 'description' => $plan->description,
            'currency' => $plan->currency, 'monthlyMinor' => $plan->monthly_minor, 'annualMinor' => $plan->annual_minor,
            'roomLimit' => $plan->room_limit, 'staffLimit' => $plan->staff_limit, 'isActive' => $plan->is_active,
            'revision' => $plan->revision, 'updatedAt' => $plan->updated_at?->toIso8601String()];
    }

    private function record(PlatformSubscriptionPlan $plan, int $actorId): void
    {
        DB::table('platform_subscription_plan_changes')->insert(['plan_id' => $plan->id, 'actor_id' => $actorId,
            'revision' => $plan->revision, 'snapshot' => json_encode($this->project($plan), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}

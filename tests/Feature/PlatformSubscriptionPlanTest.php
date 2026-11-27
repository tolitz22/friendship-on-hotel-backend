<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\PlatformSubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function subscriptionPlanOperator(): User
{
    $user = User::create(['name' => 'Operator', 'email' => 'plan-operator@example.test', 'password' => 'synthetic-password']);
    config(['platform.admin_ids' => [(string) $user->id]]);
    Sanctum::actingAs($user);

    return $user;
}

function subscriptionPlanInput(): array
{
    return ['name' => 'Synthetic plan', 'code' => 'synthetic-plan', 'description' => 'Test package', 'monthlyMinor' => 499900,
        'annualMinor' => 4999900, 'roomLimit' => 30, 'staffLimit' => null, 'isActive' => false];
}

it('restricts every plan operation to dedicated platform operators', function (): void {
    $path = '/api/v1/platform/subscription-plans';
    $this->getJson($path)->assertUnauthorized();
    $this->postJson($path, subscriptionPlanInput())->assertUnauthorized();
    $user = subscriptionPlanOperator();
    $hotel = Hotel::create(['name' => 'Existing']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    $this->getJson($path)->assertForbidden();
    $this->postJson($path, subscriptionPlanInput())->assertForbidden();
    $this->putJson($path.'/1', [])->assertForbidden();
    $this->getJson($path.'/1/history')->assertForbidden();
    expect(PlatformSubscriptionPlan::count())->toBe(0);
});

it('creates exact PHP prices, versions changes and rejects stale edits without affecting hotels', function (): void {
    $operator = subscriptionPlanOperator();
    $hotel = Hotel::create(['name' => 'Existing']);
    $path = '/api/v1/platform/subscription-plans';
    $plan = $this->postJson($path, subscriptionPlanInput())->assertCreated()
        ->assertJsonPath('data.monthlyMinor', 499900)->assertJsonPath('data.staffLimit', null)
        ->assertJsonPath('data.currency', 'PHP')->assertJsonPath('data.revision', 1)->json('data');
    $id = $plan['id'];
    $this->postJson($path, subscriptionPlanInput())->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->getJson($path.'?status=active')->assertOk()->assertJsonPath('meta.total', 0);
    $input = subscriptionPlanInput();
    unset($input['code']);
    $input['revision'] = 1;
    $input['monthlyMinor'] = 599900;
    $input['isActive'] = true;
    $this->putJson($path.'/'.$id, $input)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.isActive', true);
    $this->putJson($path.'/'.$id, $input)->assertStatus(409);
    $input['revision'] = 2;
    $this->putJson($path.'/'.$id, $input)->assertOk()->assertJsonPath('data.revision', 2);
    $this->getJson($path.'?status=active')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson($path.'/'.$id.'/history')->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.snapshot.monthlyMinor', 599900)->assertJsonPath('data.1.snapshot.monthlyMinor', 499900)
        ->assertJsonPath('data.0.actorId', (string) $operator->id);
    $input['isActive'] = false;
    $this->putJson($path.'/'.$id, $input)->assertOk()->assertJsonPath('data.revision', 3);
    expect(DB::table('platform_subscription_plan_changes')->count())->toBe(3);
    expect($hotel->fresh()->name)->toBe('Existing');
    expect($operator->memberships()->count())->toBe(0);
});

it('rejects invalid prices, limits and mutable plan codes', function (): void {
    subscriptionPlanOperator();
    $path = '/api/v1/platform/subscription-plans';
    $this->postJson($path, array_replace(subscriptionPlanInput(), ['monthlyMinor' => -1, 'annualMinor' => 1.5, 'roomLimit' => 0]))
        ->assertUnprocessable()->assertJsonValidationErrors(['monthlyMinor', 'annualMinor', 'roomLimit']);
    $plan = $this->postJson($path, subscriptionPlanInput())->assertCreated()->json('data');
    $this->putJson($path.'/'.$plan['id'], [...subscriptionPlanInput(), 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('code');
    expect(DB::table('platform_subscription_plan_changes')->count())->toBe(1);
});

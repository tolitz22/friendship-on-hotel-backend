<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\PlatformSubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function lifecycleOperator(): User
{
    $user = User::create(['name' => 'Operator', 'email' => 'lifecycle@example.test', 'password' => 'synthetic-password']);
    config(['platform.admin_ids' => [(string) $user->id]]);
    Sanctum::actingAs($user);

    return $user;
}

function lifecyclePlan(): PlatformSubscriptionPlan
{
    return PlatformSubscriptionPlan::create(['name' => 'Synthetic', 'code' => 'synthetic', 'currency' => 'PHP',
        'monthly_minor' => 499900, 'annual_minor' => 4999900, 'room_limit' => 30, 'staff_limit' => null, 'is_active' => true, 'revision' => 1]);
}

it('protects subscription operations and leaves unassigned hotels alone', function (): void {
    $path = '/api/v1/platform/hotel-subscriptions';
    $hotel = Hotel::create(['name' => 'Synthetic']);
    $this->getJson($path)->assertUnauthorized();
    $user = lifecycleOperator();
    $this->getJson($path.'?status=unassigned')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.subscription', null);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    $this->getJson($path)->assertForbidden();
    $this->putJson($path.'/'.$hotel->id, [])->assertForbidden();
    $this->getJson($path.'/'.$hotel->id.'/history')->assertForbidden();
    expect(DB::table('platform_hotel_subscriptions')->count())->toBe(0);
});

it('tracks trial expiry, conversion, renewals and immutable terms', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-01-31 10:00:00', 'Asia/Manila'));
    $user = lifecycleOperator();
    $hotel = Hotel::create(['name' => 'Synthetic']);
    $plan = lifecyclePlan();
    $path = '/api/v1/platform/hotel-subscriptions';
    $url = $path.'/'.$hotel->id;
    $terms = ['planId' => $plan->id, 'planRevision' => 1, 'cycle' => 'monthly'];
    $this->putJson($url, [...$terms, 'action' => 'trial', 'revision' => 0, 'trialDays' => 14])->assertOk()
        ->assertJsonPath('data.status', 'trial')->assertJsonPath('data.expiresOn', '2026-02-14');
    $this->getJson($path.'?status=trial')->assertOk()->assertJsonPath('meta.total', 1);
    $this->putJson($url, [...$terms, 'action' => 'trial', 'revision' => 1, 'trialDays' => 14])->assertUnprocessable();
    $this->putJson($url, [...$terms, 'action' => 'assign', 'revision' => 1])->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.expiresOn', '2026-02-28')->assertJsonPath('data.priceMinor', 499900);
    $plan->update(['monthly_minor' => 799900, 'room_limit' => 60, 'revision' => 2, 'is_active' => false]);
    $this->putJson($url, ['action' => 'renew', 'revision' => 2])->assertOk()
        ->assertJsonPath('data.expiresOn', '2026-03-28')->assertJsonPath('data.priceMinor', 499900)->assertJsonPath('data.roomLimit', 30);
    $this->putJson($url, ['action' => 'renew', 'revision' => 2])->assertStatus(409);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-03-28 00:00:00', 'Asia/Manila'));
    $this->getJson($path.'?status=expired')->assertOk()->assertJsonPath('data.0.subscription.status', 'expired');
    $this->putJson($url, ['action' => 'renew', 'revision' => 3])->assertOk()
        ->assertJsonPath('data.startsOn', '2026-03-28')->assertJsonPath('data.expiresOn', '2026-04-28');
    $this->getJson($url.'/history')->assertOk()->assertJsonPath('meta.total', 4)
        ->assertJsonPath('data.0.actorId', (string) $user->id)->assertJsonPath('data.3.action', 'trial');
    expect(DB::table('platform_hotel_subscriptions')->count())->toBe(1);
    expect($hotel->fresh()->name)->toBe('Synthetic');
});

it('rejects stale plan terms, inactive plans and invalid trial durations before writing', function (): void {
    lifecycleOperator();
    $hotel = Hotel::create(['name' => 'Synthetic']);
    $plan = lifecyclePlan();
    $url = '/api/v1/platform/hotel-subscriptions/'.$hotel->id;
    $input = ['action' => 'trial', 'revision' => 0, 'planId' => $plan->id, 'planRevision' => 1, 'cycle' => 'annual', 'trialDays' => 91];
    $this->putJson($url, $input)->assertUnprocessable();
    $input['trialDays'] = 14;
    $plan->update(['revision' => 2]);
    $this->putJson($url, $input)->assertStatus(409);
    $input['planRevision'] = 2;
    $plan->update(['is_active' => false]);
    $this->putJson($url, $input)->assertUnprocessable();
    expect(DB::table('platform_hotel_subscription_changes')->count())->toBe(0);
});

<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\PlatformHotelSubscription;
use App\Models\PlatformSubscriptionPlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

function capacityHotel(): array
{
    $hotel = Hotel::create(['name' => 'Capacity QA']);
    $admin = User::create(['name' => 'Admin', 'email' => 'capacity-admin@example.test', 'password' => 'synthetic-password']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    $plan = PlatformSubscriptionPlan::create(['name' => 'QA', 'code' => 'capacity-qa', 'currency' => 'PHP', 'monthly_minor' => 100,
        'annual_minor' => 1000, 'room_limit' => 1, 'staff_limit' => 2, 'is_active' => true, 'revision' => 1]);
    $agreement = PlatformHotelSubscription::create(['hotel_id' => $hotel->id, 'plan_id' => $plan->id, 'plan_revision' => 1,
        'plan_name' => 'QA', 'currency' => 'PHP', 'cycle' => 'monthly', 'price_minor' => 100, 'room_limit' => 1, 'staff_limit' => 2,
        'kind' => 'paid', 'starts_on' => '2026-01-01', 'expires_on' => '2026-02-01', 'revision' => 1]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);

    return [$hotel, $admin, $agreement];
}

it('blocks only new rooms at the snapshotted limit and leaves reads and edits available', function (): void {
    [$hotel, $admin, $agreement] = capacityHotel();
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $base = '/api/v1/hotels/'.$hotel->id;
    $room = $this->postJson($base.'/rooms', ['roomTypeId' => $type->id, 'number' => '101'])->assertCreated()->json('data.id');
    $this->postJson($base.'/rooms', ['roomTypeId' => $type->id, 'number' => '102'])->assertUnprocessable()->assertJsonValidationErrors('subscriptionLimit');
    $this->patchJson($base.'/rooms/'.$room, ['number' => '103'])->assertOk();
    $this->getJson($base.'/rooms')->assertOk();
    $this->getJson($base.'/subscription-capacity')->assertOk()->assertJsonPath('data.rooms.state', 'full')->assertJsonPath('data.rooms.used', 1);
    $agreement->update(['room_limit' => null]);
    $this->postJson($base.'/rooms', ['roomTypeId' => $type->id, 'number' => '102'])->assertCreated();
    expect($hotel->rooms()->count())->toBe(2);
});

it('blocks creation attachment and reactivation while allowing deactivation and permission changes', function (): void {
    [$hotel, $admin, $agreement] = capacityHotel();
    $base = '/api/v1/hotels/'.$hotel->id.'/staff';
    $worker = User::create(['name' => 'Worker', 'email' => 'capacity-worker@example.test', 'password' => 'synthetic-password']);
    $other = User::create(['name' => 'Other', 'email' => 'capacity-other@example.test', 'password' => 'synthetic-password']);
    $this->postJson($base, ['email' => $worker->email, 'role' => 'housekeeping'])->assertCreated();
    $this->postJson($base, ['email' => $other->email, 'role' => 'maintenance'])->assertUnprocessable()->assertJsonValidationErrors('subscriptionLimit');
    $this->postJson($base.'/create-account', ['name' => 'New staff', 'email' => 'capacity-new@example.test', 'role' => 'housekeeping',
        'password' => 'InitialPassword123', 'password_confirmation' => 'InitialPassword123'])->assertUnprocessable()->assertJsonValidationErrors('subscriptionLimit');
    expect(User::where('email', 'capacity-new@example.test')->exists())->toBeFalse();
    $this->patchJson($base.'/'.$worker->id, ['role' => 'maintenance'])->assertOk();
    $this->patchJson($base.'/'.$worker->id, ['isActive' => false])->assertOk();
    $this->postJson($base, ['email' => $other->email, 'role' => 'maintenance'])->assertCreated();
    $this->patchJson($base.'/'.$worker->id, ['isActive' => true])->assertUnprocessable()->assertJsonValidationErrors('subscriptionLimit');
    expect($hotel->memberships()->where('is_active', true)->count())->toBe(2);
});

it('checks invitation capacity again at acceptance without consuming the invitation', function (): void {
    Notification::fake();
    [$hotel, $admin, $agreement] = capacityHotel();
    $base = '/api/v1/hotels/'.$hotel->id.'/staff';
    $this->postJson($base.'/invitations', ['name' => 'Invited staff', 'email' => 'capacity-invite@example.test', 'role' => 'housekeeping'])->assertCreated();
    $token = str_repeat('c', 64);
    DB::table('staff_invitations')->where('hotel_id', $hotel->id)->update(['token_hash' => hash('sha256', $token)]);
    $agreement->update(['staff_limit' => 1]);
    $this->postJson($base.'/invitations', ['name' => 'Another staff', 'email' => 'capacity-invite2@example.test', 'role' => 'housekeeping'])->assertUnprocessable()->assertJsonValidationErrors('subscriptionLimit');
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $token, 'password' => 'InitialPassword123', 'password_confirmation' => 'InitialPassword123'])
        ->assertUnprocessable()->assertJsonValidationErrors('subscriptionLimit');
    expect(DB::table('staff_invitations')->where('hotel_id', $hotel->id)->value('accepted_at'))->toBeNull();
    expect(User::where('email', 'capacity-invite@example.test')->exists())->toBeFalse();
});

it('keeps unassigned hotels unrestricted and supports advisory rollout', function (): void {
    [$hotel, $admin, $agreement] = capacityHotel();
    $service = app(\App\Services\PlatformSubscriptionCapacity::class);
    $agreement->update(['staff_limit' => 1]);
    config(['platform.enforce_capacity' => false]);
    $service->requireSpace((int) $hotel->id, 'staff');
    expect($service->snapshot($hotel)['enforced'])->toBeFalse();
    config(['platform.enforce_capacity' => true]);
    $other = Hotel::create(['name' => 'Unassigned']);
    $service->requireSpace((int) $other->id, 'staff');
    expect($service->snapshot($other)['assigned'])->toBeFalse();
    $this->getJson('/api/v1/hotels/'.$other->id.'/subscription-capacity')->assertNotFound();
});

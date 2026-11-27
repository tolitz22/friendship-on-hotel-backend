<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

function platformHotelOperator(): User
{
    $operator = User::create(['name' => 'Operator', 'email' => 'hotel-operator@example.test', 'password' => 'synthetic-password']);
    config(['platform.admin_ids' => [(string) $operator->id]]);
    Sanctum::actingAs($operator);

    return $operator;
}

function platformHotelInput(): array
{
    return ['name' => 'Synthetic Hotel', 'adminName' => 'Synthetic Manager', 'adminEmail' => 'new-manager@example.test',
        'password' => 'InitialPassword123', 'password_confirmation' => 'InitialPassword123'];
}

it('denies anonymous and property administrators even if their ID is allowlisted', function (): void {
    $this->getJson('/api/v1/platform/hotels')->assertUnauthorized();
    $this->postJson('/api/v1/platform/hotels', platformHotelInput())->assertUnauthorized();
    $user = platformHotelOperator();
    $hotel = Hotel::create(['name' => 'Existing']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    $this->getJson('/api/v1/platform/hotels')->assertForbidden();
    $this->postJson('/api/v1/platform/hotels', platformHotelInput())->assertForbidden();
    expect(Hotel::count())->toBe(1);
});

it('creates a new property and dedicated manager atomically without granting platform access', function (): void {
    $operator = platformHotelOperator();
    $existing = Hotel::create(['name' => 'Existing']);
    $result = $this->postJson('/api/v1/platform/hotels', platformHotelInput())->assertCreated()
        ->assertJsonPath('data.passwordChangeRequired', true)->assertDontSee('InitialPassword123')->json('data');
    $admin = User::findOrFail($result['adminId']);
    expect($admin->must_change_password)->toBeTrue()
        ->and(Hash::check('InitialPassword123', $admin->password))->toBeTrue()
        ->and($admin->memberships()->count())->toBe(1)
        ->and((string) $admin->memberships()->first()->hotel_id)->toBe($result['id'])
        ->and($admin->memberships()->first()->role)->toBe('admin')
        ->and($operator->memberships()->count())->toBe(0)
        ->and(\App\Support\PlatformAccess::allowed($admin))->toBeFalse()
        ->and($existing->memberships()->count())->toBe(0);
    $this->postJson('/api/v1/platform/hotels', platformHotelInput())->assertUnprocessable()->assertJsonValidationErrors('adminEmail');
    expect(Hotel::count())->toBe(2);
    $this->getJson('/api/v1/platform/hotels?search=synthetic')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.activeAdminCount', 1)
        ->assertJsonPath('data.0.activeStaffCount', 1)->assertJsonPath('data.0.roomCount', 0)
        ->assertJsonPath('data.0.currency', 'PHP')->assertDontSee('new-manager@example.test');
    $this->getJson('/api/v1/platform/hotels?search=%25')->assertOk()->assertJsonPath('meta.total', 0);
    $admin->update(['must_change_password' => false]);
    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/platform/hotels')->assertForbidden();
});

it('validates initial passwords before creating any records', function (): void {
    platformHotelOperator();
    $this->postJson('/api/v1/platform/hotels', array_replace(platformHotelInput(), ['password_confirmation' => 'wrong']))
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(Hotel::count())->toBe(0);
});

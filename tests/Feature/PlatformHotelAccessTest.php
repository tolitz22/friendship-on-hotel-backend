<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function accessQaUser(string $email): User
{
    return User::create(['name' => 'Synthetic operator', 'email' => $email, 'password' => 'synthetic-password']);
}

it('requires dedicated platform authorization, restriction first, revision and clearance', function (): void {
    $hotel = Hotel::create(['name' => 'Synthetic']);
    $url = '/api/v1/platform/hotels/'.$hotel->id.'/access';
    $this->getJson($url)->assertUnauthorized();
    $operator = accessQaUser('access-op@example.test');
    config(['platform.admin_ids' => [(string) $operator->id]]);
    Sanctum::actingAs($operator);
    $input = ['state' => 'suspended', 'revision' => 0, 'reason' => 'Synthetic service pause', 'confirmed' => true, 'clearanceConfirmed' => true];
    $this->putJson($url, $input)->assertUnprocessable();
    $this->putJson($url, array_replace($input, ['state' => 'restricted']))->assertOk()->assertJsonPath('data.revision', 1);
    $this->putJson($url, $input)->assertStatus(409);
    $this->putJson($url, array_replace($input, ['revision' => 1, 'clearanceConfirmed' => false]))->assertUnprocessable();
    $this->putJson($url, array_replace($input, ['revision' => 1]))->assertOk()->assertJsonPath('data.state', 'suspended');
    $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 2);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $operator->id, 'role' => 'admin', 'is_active' => true]);
    $this->putJson($url, array_replace($input, ['state' => 'active', 'revision' => 2]))->assertForbidden();
});

it('protects existing hotel access and blocks suspended writes and new booking routes only when appropriate', function (): void {
    $hotel = Hotel::create(['name' => 'Synthetic']);
    $other = Hotel::create(['name' => 'Other']);
    $admin = accessQaUser('access-admin@example.test');
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $base = '/api/v1/hotels/'.$hotel->id;
    $this->getJson($base.'/rooms')->assertOk();
    $hotel->forceFill(['platform_access_state' => 'restricted'])->save();
    foreach (['bookings', 'group-reservations', 'group-allotments'] as $route) {
        $this->postJson($base.'/'.$route, [])->assertStatus(423);
    }
    $this->postJson($base.'/bookings/999/check-out', [])->assertNotFound();
    $hotel->forceFill(['platform_access_state' => 'suspended'])->save();
    $this->getJson($base.'/rooms')->assertOk();
    $this->postJson($base.'/rooms', [])->assertStatus(423);
    $this->patchJson($base.'/staff/'.$admin->id, ['isActive' => false])->assertStatus(423);
    $this->getJson('/api/v1/hotels/'.$other->id.'/rooms')->assertNotFound();
    $hotel->forceFill(['platform_access_state' => 'active'])->save();
    $this->postJson($base.'/rooms', [])->assertUnprocessable();
    expect($hotel->memberships()->where('is_active', true)->count())->toBe(1);
});

it('prevents suspension with outstanding reservations and allows later reactivation', function (): void {
    $hotel = Hotel::create(['name' => 'Synthetic']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $id = DB::table('reservation_records')->insertGetId(['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'reference' => 'QA-ACCESS-1', 'check_in' => '2026-12-01', 'check_out' => '2026-12-02', 'lifecycle_state' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    $operator = accessQaUser('access-clear@example.test');
    config(['platform.admin_ids' => [(string) $operator->id]]);
    Sanctum::actingAs($operator);
    $url = '/api/v1/platform/hotels/'.$hotel->id.'/access';
    $input = ['state' => 'restricted', 'revision' => 0, 'reason' => 'Synthetic service pause', 'confirmed' => true];
    $this->putJson($url, $input)->assertOk();
    $this->getJson($url)->assertOk()->assertJsonPath('data.blockers.openReservations', 1);
    $input = array_replace($input, ['state' => 'suspended', 'revision' => 1, 'clearanceConfirmed' => true]);
    $this->putJson($url, $input)->assertUnprocessable();
    DB::table('reservation_records')->where('id', $id)->update(['lifecycle_state' => 'cancelled']);
    $this->putJson($url, $input)->assertOk();
    $this->putJson($url, array_replace($input, ['state' => 'active', 'revision' => 2]))->assertOk()->assertJsonPath('data.state', 'active');
    expect(DB::table('reservation_records')->where('id', $id)->exists())->toBeTrue();
});

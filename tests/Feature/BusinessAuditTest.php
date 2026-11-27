<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('reads existing hotel audit sources without exposing snapshots or other properties', function (): void {
    $hotel = Hotel::create(['name' => 'Audit hotel']);
    $other = Hotel::create(['name' => 'Other hotel']);
    $admin = User::create(['name' => 'Audit admin', 'email' => 'business-audit@example.test', 'password' => 'test-password']);
    $worker = User::create(['name' => 'Worker', 'email' => 'business-worker@example.test', 'password' => 'test-password']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $worker->id, 'role' => 'housekeeping', 'is_active' => true]);
    $time = now()->subMinute();
    DB::table('hotel_staff_events')->insert([
        ['hotel_id' => $hotel->id, 'actor_id' => $admin->id, 'target_user_id' => $worker->id, 'action' => 'updated',
            'before_state' => null, 'after_state' => '{"secret":"private-staff-snapshot"}', 'created_at' => $time],
        ['hotel_id' => $other->id, 'actor_id' => $admin->id, 'target_user_id' => $worker->id, 'action' => 'foreign_action',
            'before_state' => null, 'after_state' => '{}', 'created_at' => $time],
    ]);
    DB::table('catalog_audit_logs')->insert([
        'hotel_id' => $hotel->id, 'actor_id' => $admin->id, 'subject_type' => 'room',
        'subject_id' => 1, 'changes_before' => '{}', 'changes_after' => '{"private":"catalog-data"}', 'created_at' => $time,
    ]);
    $url = '/api/v1/hotels/'.$hotel->id.'/business-audit';
    $this->getJson($url)->assertUnauthorized();
    Sanctum::actingAs($worker, ['hotel:read']);
    $this->getJson($url)->assertForbidden();
    Sanctum::actingAs($admin, ['hotel:read', 'hotel:write']);
    $first = $this->getJson($url.'?per_page=1')->assertOk()->assertJsonPath('data.hotelId', (string) $hotel->id)
        ->assertJsonPath('data.items.0.category', 'catalog')->assertJsonPath('meta.readOnly', true);
    $cursor = $first->json('meta.nextCursor');
    expect($cursor)->toBeString();
    $second = $this->getJson($url.'?per_page=1&cursor='.urlencode($cursor))->assertOk()
        ->assertJsonPath('data.items.0.category', 'staff')->assertJsonPath('meta.nextCursor', null);
    expect($first->json('data.items.0.id'))->not->toBe($second->json('data.items.0.id'));
    $all = $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.items');
    expect($all->getContent())->not->toContain('private-staff-snapshot', 'catalog-data', 'foreign_action');
    $this->getJson($url.'?category=staff')->assertOk()->assertJsonCount(1, 'data.items');
    $this->getJson($url.'?category=invalid')->assertUnprocessable();
    $this->getJson($url.'?days=91')->assertUnprocessable();
    $this->getJson('/api/v1/hotels/'.$other->id.'/business-audit')->assertNotFound();
    expect(DB::table('hotel_staff_events')->count())->toBe(2);
});

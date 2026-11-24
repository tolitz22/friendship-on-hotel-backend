<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Models\HotelMembership;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('applies bulk overrides atomically and rejects stale or unconfirmed replacements', function (): void {
    $hotel = Hotel::create(['name' => 'Bulk Rate Test']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $user = User::create(['name' => 'Rate Admin', 'email' => 'bulk-'.uniqid().'@example.test', 'password' => 'TestPassword123!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($user, ['hotel:read', 'hotel:write']);
    $plan = DB::table('room_rate_plans')->insertGetId(['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'name' => 'Standard', 'nightly_minor' => 200000, 'currency' => 'PHP', 'created_by' => $user->id]);
    $base = '/api/v1/hotels/'.$hotel->id.'/rate-plans/'.$plan;
    $payload = ['name' => 'Peak dates', 'startsOn' => '2026-12-24', 'endsOn' => '2026-12-26',
        'nightlyMinor' => 300000, 'replaceExisting' => false, 'expectedRuleIds' => []];
    $ids = $this->postJson($base.'/bulk-overrides', $payload)->assertCreated()
        ->assertJsonPath('data.nightsUpdated', 3)->assertJsonPath('data.appliedToBookings', false)->json('data.ruleIds');
    $this->postJson($base.'/bulk-overrides', $payload)->assertStatus(409);
    $this->postJson($base.'/bulk-overrides', array_replace($payload, ['expectedRuleIds' => $ids]))->assertStatus(409);
    expect(DB::table('room_rate_rules')->count())->toBe(3);
    $this->postJson($base.'/bulk-overrides', array_replace($payload, ['expectedRuleIds' => $ids, 'replaceExisting' => true,
        'nightlyMinor' => 350000]))->assertCreated()->assertJsonPath('data.replacedCount', 3);
    expect(DB::table('room_rate_rules')->whereNotNull('retired_at')->count())->toBe(3);
    $params = http_build_query(['roomTypeId' => $type->id, 'checkIn' => '2026-12-24', 'checkOut' => '2026-12-27']);
    $this->getJson($base.'/seasonal-preview?'.$params)->assertOk()->assertJsonPath('data.roomSubtotalMinor', 1050000);
    $this->postJson($base.'/bulk-overrides', array_replace($payload, ['endsOn' => '2027-02-01']))->assertUnprocessable();
    expect(DB::table('folio_entries')->count())->toBe(0);
});

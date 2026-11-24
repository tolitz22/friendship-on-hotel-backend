<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Models\HotelMembership;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('previews seasonal overrides and preserves retired rule history without changing live quotes', function (): void {
    $hotel = Hotel::create(['name' => 'Seasonal Test']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $user = User::create(['name' => 'Rate Admin', 'email' => 'rates-'.uniqid().'@example.test', 'password' => 'TestPassword123!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($user, ['hotel:read', 'hotel:write']);
    $plan = DB::table('room_rate_plans')->insertGetId(['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'name' => 'Standard', 'nightly_minor' => 200000, 'currency' => 'PHP', 'created_by' => $user->id]);
    $base = '/api/v1/hotels/'.$hotel->id;
    $path = $base.'/rate-plans/'.$plan.'/rules';
    $season = ['name' => 'December', 'kind' => 'seasonal', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31', 'nightlyMinor' => 280000];
    $id = $this->postJson($path, $season)->assertCreated()->json('data.id');
    $this->postJson($path, $season)->assertUnprocessable();
    $override = $this->postJson($path, ['name' => 'Christmas', 'kind' => 'date_override',
        'startsOn' => '2026-12-25', 'endsOn' => '2026-12-25', 'nightlyMinor' => 350000])->assertCreated()->json('data.id');
    $params = http_build_query(['roomTypeId' => $type->id, 'checkIn' => '2026-12-24', 'checkOut' => '2026-12-27']);
    $preview = $base.'/rate-plans/'.$plan.'/seasonal-preview?'.$params;
    $this->getJson($preview)->assertOk()->assertJsonPath('data.roomSubtotalMinor', 910000)
        ->assertJsonPath('data.nightlyRates.1.source', 'date_override')->assertJsonPath('data.appliedToBookings', false);
    $new = $this->putJson($path.'/'.$id, array_replace($season, ['nightlyMinor' => 300000]))->assertOk()->json('data.id');
    expect((string) DB::table('room_rate_rules')->where('id', $new)->value('replaces_rule_id'))->toBe($id);
    $this->postJson($path.'/'.$override.'/retire')->assertOk();
    $this->postJson($path.'/'.$override.'/retire')->assertStatus(409);
    $this->getJson($preview)->assertOk()->assertJsonPath('data.roomSubtotalMinor', 900000);
    $this->getJson($base.'/room-quotes?'.$params.'&ratePlanId='.$plan)->assertOk()->assertJsonPath('data.roomSubtotalMinor', 600000);
    expect(DB::table('room_rate_rules')->whereNotNull('retired_at')->count())->toBe(2);
});

it('applies configured weekend days and holiday precedence without activating booking prices', function (): void {
    $hotel = Hotel::create(['name' => 'Weekend Test']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $user = User::create(['name' => 'Rate Admin', 'email' => 'weekend-'.uniqid().'@example.test', 'password' => 'TestPassword123!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($user, ['hotel:read', 'hotel:write']);
    $plan = DB::table('room_rate_plans')->insertGetId(['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'name' => 'Standard', 'nightly_minor' => 200000, 'currency' => 'PHP', 'created_by' => $user->id]);
    $base = '/api/v1/hotels/'.$hotel->id;
    $path = $base.'/rate-plans/'.$plan.'/rules';
    $weekend = ['name' => 'Friday and Saturday', 'kind' => 'weekend', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31', 'weekendDays' => [5, 6], 'nightlyMinor' => 250000];
    $this->postJson($path, array_replace($weekend, ['weekendDays' => [8]]))->assertUnprocessable();
    $this->postJson($path, $weekend)->assertCreated();
    $this->postJson($path, $weekend)->assertUnprocessable();
    $this->postJson($path, ['name' => 'Season', 'kind' => 'seasonal', 'startsOn' => '2026-12-25', 'endsOn' => '2026-12-25', 'nightlyMinor' => 280000])->assertCreated();
    $holiday = $this->postJson($path, ['name' => 'Holiday', 'kind' => 'holiday', 'startsOn' => '2026-12-25', 'endsOn' => '2026-12-25', 'nightlyMinor' => 300000])->assertCreated()->json('data.id');
    $params = http_build_query(['roomTypeId' => $type->id, 'checkIn' => '2026-12-24', 'checkOut' => '2026-12-28']);
    $preview = $base.'/rate-plans/'.$plan.'/seasonal-preview?'.$params;
    $this->getJson($preview)->assertOk()->assertJsonPath('data.roomSubtotalMinor', 950000)
        ->assertJsonPath('data.nightlyRates.0.source', 'base')->assertJsonPath('data.nightlyRates.1.source', 'holiday')
        ->assertJsonPath('data.nightlyRates.2.source', 'weekend')->assertJsonPath('data.nightlyRates.3.source', 'base');
    $this->postJson($path, ['name' => 'Override', 'kind' => 'date_override', 'startsOn' => '2026-12-25', 'endsOn' => '2026-12-25', 'nightlyMinor' => 350000])->assertCreated();
    $this->getJson($preview)->assertOk()->assertJsonPath('data.roomSubtotalMinor', 1000000)
        ->assertJsonPath('data.nightlyRates.1.source', 'date_override');
    $this->postJson($path.'/'.$holiday.'/retire')->assertOk();
    $this->getJson($base.'/room-quotes?'.$params.'&ratePlanId='.$plan)->assertOk()->assertJsonPath('data.roomSubtotalMinor', 800000);
});

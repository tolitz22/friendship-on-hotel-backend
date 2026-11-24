<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Models\HotelMembership;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('requires explicit corporate or promo eligibility, prevents stacking and preserves live rates', function (): void {
    $hotel = Hotel::create(['name' => 'Negotiated Test']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $user = User::create(['name' => 'Rate Admin', 'email' => 'negotiated-'.uniqid().'@example.test', 'password' => 'TestPassword123!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($user, ['hotel:read', 'hotel:write']);
    $plan = DB::table('room_rate_plans')->insertGetId(['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'name' => 'Standard', 'nightly_minor' => 200000, 'currency' => 'PHP', 'created_by' => $user->id]);
    $base = '/api/v1/hotels/'.$hotel->id;
    $path = $base.'/rate-plans/'.$plan.'/rules';
    $rule = ['name' => 'Corporate', 'kind' => 'corporate', 'startsOn' => '2026-12-01',
        'endsOn' => '2026-12-31', 'eligibilityCode' => 'ACME', 'minimumNights' => 2, 'nightlyMinor' => 150000];
    $id = $this->postJson($path, $rule)->assertCreated()->json('data.id');
    $this->postJson($path, $rule)->assertUnprocessable();
    $this->postJson($path, array_replace($rule, ['eligibilityCode' => 'OTHER']))->assertCreated();
    $this->postJson($path, array_replace($rule, ['kind' => 'promotion', 'eligibilityCode' => 'SAVE', 'nightlyMinor' => 170000]))->assertCreated();
    $params = ['roomTypeId' => $type->id, 'checkIn' => '2026-12-24', 'checkOut' => '2026-12-27'];
    $preview = $base.'/rate-plans/'.$plan.'/seasonal-preview?';
    $this->getJson($preview.http_build_query($params))->assertOk()->assertJsonPath('data.roomSubtotalMinor', 600000);
    $this->getJson($preview.http_build_query($params + ['corporateCode' => 'acme']))->assertOk()
        ->assertJsonPath('data.roomSubtotalMinor', 450000)->assertJsonPath('data.nightlyRates.0.source', 'corporate');
    $this->getJson($preview.http_build_query($params + ['promoCode' => 'SAVE']))->assertOk()->assertJsonPath('data.roomSubtotalMinor', 510000);
    $this->getJson($preview.http_build_query($params + ['corporateCode' => 'INVALID']))->assertUnprocessable();
    $this->getJson($preview.http_build_query($params + ['corporateCode' => 'ACME', 'promoCode' => 'SAVE']))->assertUnprocessable();
    $this->getJson($preview.http_build_query(array_replace($params, ['checkOut' => '2026-12-25', 'corporateCode' => 'ACME'])))->assertUnprocessable();
    $this->getJson($preview.http_build_query(array_replace($params, ['checkOut' => '2027-01-02', 'corporateCode' => 'ACME'])))->assertUnprocessable();
    $this->postJson($path.'/'.$id.'/retire')->assertOk();
    $this->getJson($preview.http_build_query($params + ['corporateCode' => 'ACME']))->assertUnprocessable();
    $this->getJson($base.'/room-quotes?'.http_build_query($params + ['ratePlanId' => $plan]))->assertOk()->assertJsonPath('data.roomSubtotalMinor', 600000);
});

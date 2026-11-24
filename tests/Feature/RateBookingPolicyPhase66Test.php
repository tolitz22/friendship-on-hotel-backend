<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Models\HotelMembership;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('versions rate booking policies and previews capped deposits and cancellation fees without financial writes', function (): void {
    $hotel = Hotel::create(['name' => 'Policy Test']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $user = User::create(['name' => 'Rate Admin', 'email' => 'policy-'.uniqid().'@example.test', 'password' => 'TestPassword123!']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    Sanctum::actingAs($user, ['hotel:read', 'hotel:write']);
    $plan = DB::table('room_rate_plans')->insertGetId(['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'name' => 'Standard', 'nightly_minor' => 200000, 'currency' => 'PHP', 'created_by' => $user->id]);
    $base = '/api/v1/hotels/'.$hotel->id.'/rate-plans/'.$plan;
    $input = ['expectedVersion' => 0, 'depositKind' => 'percent', 'depositValue' => 3000,
        'freeCancelDays' => 2, 'lateCancelKind' => 'first_night', 'lateCancelValue' => 0,
        'noShowKind' => 'full', 'noShowValue' => 0, 'reason' => 'Initial policy'];
    $this->postJson($base.'/booking-policies', array_replace($input, ['depositValue' => 10001]))->assertUnprocessable();
    $this->postJson($base.'/booking-policies', $input)->assertCreated()->assertJsonPath('data.version', 1);
    $this->postJson($base.'/booking-policies', $input)->assertStatus(409);
    $params = ['checkIn' => '2026-12-24', 'checkOut' => '2026-12-27', 'cancellationDate' => '2026-12-22'];
    $preview = $base.'/booking-policy-preview?';
    $this->getJson($preview.http_build_query($params))->assertOk()->assertJsonPath('data.depositRequiredMinor', 180000)
        ->assertJsonPath('data.cancellationFeeMinor', 0)->assertJsonPath('data.noShowFeeMinor', 600000)
        ->assertJsonPath('data.appliedToBookings', false);
    $this->getJson($preview.http_build_query(array_replace($params, ['cancellationDate' => '2026-12-23'])))
        ->assertOk()->assertJsonPath('data.cancellationFeeMinor', 200000);
    $this->postJson($base.'/booking-policies', array_replace($input, ['expectedVersion' => 1, 'depositKind' => 'fixed', 'depositValue' => 999999]))
        ->assertCreated()->assertJsonPath('data.version', 2);
    $this->getJson($preview.http_build_query($params))->assertOk()->assertJsonPath('data.depositRequiredMinor', 600000);
    expect(DB::table('rate_booking_policies')->count())->toBe(2);
    expect(DB::table('folio_entries')->count())->toBe(0);
    expect(DB::table('reservation_records')->count())->toBe(0);
});

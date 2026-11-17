<?php

declare(strict_types=1);

use App\Domain\Reservations\ReservationPolicyReadiness;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('returns missing fields without claiming approval or booking safety', function (): void {
    $result=(new ReservationPolicyReadiness())->assess([]);
    expect($result['configurationComplete'])->toBeFalse();
    expect($result['readyForBookings'])->toBeFalse();
    expect($result['approved'])->toBeFalse();
    expect($result['missingFields'])->toContain('sellableUnit', 'ratePolicy');
});

it('still blocks booking activation for completed preliminary choices', function (): void {
    $choices=[
        'sellableUnit'=>'hybrid',
        'assignmentTiming'=>'at_check_in',
        'inventoryConsumption'=>'pending_confirmed_checked_in',
        'holdPolicy'=>'timed_pending_holds',
        'maintenanceBlocks'=>'block_out_of_service_and_manual',
        'overbooking'=>'prohibited',
        'cancellationPolicy'=>'manual_review',
        'ratePolicy'=>'manual_quote_review',
    ];
    $result=(new ReservationPolicyReadiness())->assess($choices);
    expect($result['configurationComplete'])->toBeTrue();
    expect($result['readyForImplementation'])->toBeFalse();
    expect($result['readyForBookings'])->toBeFalse();
    expect(array_column($result['issues'],'code'))->toContain('hold_expiry_unspecified','inventory_locking');
});

it('highlights physically sold rooms assigned after the booking', function (): void {
    $result=(new ReservationPolicyReadiness())->assess([
        'sellableUnit'=>'physical_room','assignmentTiming'=>'at_check_in',
    ]);
    expect(array_column($result['issues'],'code'))->toContain('physical_room_assignment_conflict');
});

it('keeps policy readiness private to active hotel members', function (): void {
    $a=Hotel::create(['name'=>'Policy A']);
    $b=Hotel::create(['name'=>'Policy B']);
    $url='/api/v1/hotels/'.$a->id.'/reservation-policy-readiness';
    $this->getJson($url)->assertUnauthorized();

    $staff=User::create([
        'name'=>'Staff','email'=>'readiness@example.test','password'=>'sample-password'
    ]);
    HotelMembership::create([
        'hotel_id'=>$a->id,'user_id'=>$staff->id,'role'=>'front_desk','is_active'=>true
    ]);
    Sanctum::actingAs($staff,['hotel:read']);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.draftRevision',0)
        ->assertJsonPath('data.readyForBookings',false)
        ->assertJsonPath('meta.diagnosticOnly',true);
    $this->getJson('/api/v1/hotels/'.$b->id.'/reservation-policy-readiness')->assertNotFound();
});

it('evaluates the latest saved draft without enabling booking writes', function (): void {
    $hotel=Hotel::create(['name'=>'Saved Review']);
    $user=User::create(['name'=>'Admin','email'=>'readiness-admin@example.test','password'=>'sample-password']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>'admin','is_active'=>true]);
    Sanctum::actingAs($user,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$hotel->id;
    $this->patchJson($base.'/reservation-policy-draft',[
        'expectedRevision'=>0,
        'decisions'=>['sellableUnit'=>'physical_room','assignmentTiming'=>'at_check_in'],
    ])->assertOk();
    $this->getJson($base.'/reservation-policy-readiness')->assertOk()
        ->assertJsonPath('data.draftRevision',1)
        ->assertJsonPath('data.readyForBookings',false);
    expect(DB::table('reservation_records')->count())->toBe(0);
    $this->postJson($base.'/reservation-records',[])->assertStatus(405);
});

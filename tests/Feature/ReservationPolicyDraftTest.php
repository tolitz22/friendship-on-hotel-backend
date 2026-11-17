<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function policyStaff(Hotel $hotel, string $role = 'admin', array $abilities = ['hotel:read', 'hotel:write']): void
{
    $user = User::create(['name'=>'Policy Editor','email'=>'policy-'.uniqid().'@example.test','password'=>'test-password']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>$role,'is_active'=>true]);
    Sanctum::actingAs($user,$abilities);
}

it('starts with an unapproved draft without manufacturing reservation rules', function (): void {
    $hotel = Hotel::create(['name'=>'Blank Booking Policy']);
    policyStaff($hotel, 'front_desk', ['hotel:read']);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/reservation-policy-draft')->assertOk()
        ->assertJsonPath('data.revision',0)
        ->assertJsonPath('data.status','draft')
        ->assertJsonPath('data.effectiveForBookings',false)
        ->assertJsonPath('data.completion.complete',false)
        ->assertJsonPath('data.decisions.sellableUnit',null);
});

it('saves scoped draft revisions and rejects stale writes without overwriting', function (): void {
    $hotel = Hotel::create(['name'=>'Revisioned Policy']);
    policyStaff($hotel);
    $path='/api/v1/hotels/'.$hotel->id.'/reservation-policy-draft';

    $this->patchJson($path,[
        'expectedRevision'=>0,
        'decisions'=>['sellableUnit'=>'hybrid','assignmentTiming'=>'at_check_in'],
    ])->assertOk()->assertJsonPath('data.revision',1)
        ->assertJsonPath('data.decisions.sellableUnit','hybrid')
        ->assertJsonPath('data.effectiveForBookings',false);

    $this->patchJson($path,[
        'expectedRevision'=>0,'decisions'=>['sellableUnit'=>'physical_room'],
    ])->assertStatus(409)->assertJsonPath('data.revision',1);

    $this->patchJson($path,[
        'expectedRevision'=>1,'decisions'=>['holdPolicy'=>'timed_pending_holds'],
    ])->assertOk()->assertJsonPath('data.revision',2);

    $this->patchJson($path,[
        'expectedRevision'=>2,'decisions'=>['holdPolicy'=>'timed_pending_holds'],
    ])->assertOk()->assertJsonPath('data.revision',2);

    $this->getJson($path.'/history')->assertOk()->assertJsonCount(2,'data')
        ->assertJsonPath('data.0.revision',2)
        ->assertJsonPath('data.1.revision',1);
    expect(DB::table('reservation_policy_draft_revisions')->count())->toBe(2);
});

it('rejects unknown and unsupported policy selections', function (): void {
    $hotel=Hotel::create(['name'=>'Validated Policy']);
    policyStaff($hotel);
    $url='/api/v1/hotels/'.$hotel->id.'/reservation-policy-draft';
    $this->patchJson($url,[
        'expectedRevision'=>0,'decisions'=>['sellableUnit'=>'magic'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['decisions.sellableUnit']);
    $this->patchJson($url,[
        'expectedRevision'=>0,'decisions'=>['unapprovedFlag'=>'true'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['decisions']);
    expect(DB::table('reservation_policy_drafts')->count())->toBe(0);
});

it('keeps policy drafts isolated between hotels', function (): void {
    $a=Hotel::create(['name'=>'My Hotel']);
    $b=Hotel::create(['name'=>'Another Hotel']);
    policyStaff($a);
    $this->getJson('/api/v1/hotels/'.$b->id.'/reservation-policy-draft')->assertNotFound();
    $this->patchJson('/api/v1/hotels/'.$b->id.'/reservation-policy-draft',[
        'expectedRevision'=>0,'decisions'=>['sellableUnit'=>'hybrid'],
    ])->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$b->id.'/reservation-policy-draft/history')->assertNotFound();
});

it('only lets authorized administrators change and review draft history', function (): void {
    $hotel=Hotel::create(['name'=>'Permission Hotel']);
    $base='/api/v1/hotels/'.$hotel->id.'/reservation-policy-draft';
    $payload=['expectedRevision'=>0,'decisions'=>['sellableUnit'=>'hybrid']];
    $this->patchJson($base,$payload)->assertUnauthorized();
    policyStaff($hotel,'front_desk',['hotel:read','hotel:write']);
    $this->getJson($base)->assertOk();
    $this->patchJson($base,$payload)->assertForbidden();
    $this->getJson($base.'/history')->assertForbidden();
    policyStaff($hotel,'admin',['hotel:read']);
    $this->patchJson($base,$payload)->assertForbidden();
    expect(DB::table('reservation_policy_drafts')->count())->toBe(0);
});

it('never enables real booking routes even with a completed draft', function (): void {
    $hotel=Hotel::create(['name'=>'Planning Only']);
    policyStaff($hotel);
    $url='/api/v1/hotels/'.$hotel->id;
    $all=[
        'sellableUnit'=>'hybrid','assignmentTiming'=>'at_check_in',
        'inventoryConsumption'=>'pending_confirmed_checked_in',
        'holdPolicy'=>'timed_pending_holds',
        'maintenanceBlocks'=>'block_out_of_service_and_manual',
        'overbooking'=>'prohibited',
        'cancellationPolicy'=>'manual_review',
        'ratePolicy'=>'published_rate_plans',
    ];
    $this->patchJson($url.'/reservation-policy-draft',[
        'expectedRevision'=>0,'decisions'=>$all,
    ])->assertOk()
        ->assertJsonPath('data.completion.complete',true)
        ->assertJsonPath('data.effectiveForBookings',false)
        ->assertJsonPath('data.status','draft');
    $this->postJson($url.'/reservation-records',['reference'=>'NOT-BOOKED'])->assertStatus(405);
});

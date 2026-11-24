<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase58Actors(): array
{
    $hotel=Hotel::create(['name'=>'Phase58 Hotel','timezone'=>'Asia/Manila','currency'=>'PHP']);
    $manager=User::create(['name'=>'Audit manager','email'=>uniqid('phase58-',true).'@example.test',
        'password'=>'Password123456!']);
    $desk=User::create(['name'=>'Front desk clerk','email'=>uniqid('phase58-',true).'@example.test',
        'password'=>'Password123456!']);
    foreach ([[$manager,'admin'],[$desk,'front_desk']] as [$user,$role]) {
        HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,
            'role'=>$role,'is_active'=>true]);
    }
    return compact('hotel','manager','desk');
}

it('records one manager review of a prior business date with unchanged folio entries',function (): void {
    $this->travelTo(new DateTimeImmutable('2027-01-12T11:00:00+08:00'));
    $fx=phase58Actors();
    $base='/api/v1/hotels/'.$fx['hotel']->id.'/night-audit';
    Sanctum::actingAs($fx['manager'],['hotel:read','hotel:write']);
    $entryCount=DB::table('folio_entries')->count();
    $reviewInput=['date'=>'2027-01-11','decision'=>'clear',
        'note'=>'I reviewed the room-night preflight and confirmed no listed exceptions.',
        'acknowledged'=>true];
    $saved=$this->postJson($base.'/review',$reviewInput)->assertCreated()
        ->assertJsonPath('data.status','reviewed')
        ->assertJsonPath('data.businessDate','2027-01-11')
        ->assertJsonPath('data.financialDayClosed',false)
        ->assertJsonPath('data.ledgerLocked',false)
        ->assertJsonPath('data.gatewaySettlementVerified',false);
    $id=$saved->json('data.id');
    $this->getJson($base.'/review?date=2027-01-11')->assertOk()
        ->assertJsonPath('data.id',$id)->assertJsonPath('data.snapshot.preflightClear',true);
    $this->postJson($base.'/review',$reviewInput)->assertStatus(409);
    $this->postJson($base.'/review',array_merge($reviewInput,['date'=>'2027-01-12']))
        ->assertStatus(409);
    $this->postJson($base.'/review',array_merge($reviewInput,['date'=>'2027-01-13']))
        ->assertStatus(409);
    $this->assertDatabaseCount('night_audit_reviews',1);
    $this->assertDatabaseHas('night_audit_review_events',[
        'hotel_id'=>$fx['hotel']->id,'night_audit_review_id'=>$id,
        'actor_id'=>$fx['manager']->id,'action'=>'reviewed',
    ]);
    expect(DB::table('folio_entries')->count())->toBe($entryCount);
});

it('does not permit clear attestations when the existing preflight reports exceptions',function (): void {
    $this->travelTo(new DateTimeImmutable('2027-01-12T11:00:00+08:00'));
    $fx=phase58Actors();
    $type=RoomType::create(['hotel_id'=>$fx['hotel']->id,'name'=>'Standard','max_occupancy'=>2]);
    Room::create(['hotel_id'=>$fx['hotel']->id,'room_type_id'=>$type->id,'number'=>'401']);
    $guest=$fx['hotel']->guests()->create(['first_name'=>'Unpriced','last_name'=>'Guest']);
    Sanctum::actingAs($fx['manager'],['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$fx['hotel']->id;
    $this->postJson($base.'/bookings',[
        'idempotencyKey'=>'phase58-book-'.uniqid(),'guestId'=>$guest->id,
        'roomTypeId'=>$type->id,'checkIn'=>'2027-01-11','checkOut'=>'2027-01-13',
    ])->assertCreated();
    $input=['date'=>'2027-01-11','decision'=>'clear',
        'note'=>'Cannot certify unpriced room reservations with missing records.',
        'acknowledged'=>true];
    $this->postJson($base.'/night-audit/review',$input)->assertStatus(409);
    $this->postJson($base.'/night-audit/review',array_merge($input,['decision'=>'flag']))
        ->assertCreated()->assertJsonPath('data.status','flagged')
        ->assertJsonPath('data.snapshot.preflightClear',false)
        ->assertJsonCount(1,'data.snapshot.unpricedBookings');
    $this->assertDatabaseCount('night_audit_reviews',1);
});

it('protects night audit review from non-managers, other hotels and missing attestation',function (): void {
    $this->travelTo(new DateTimeImmutable('2027-01-12T11:00:00+08:00'));
    $fx=phase58Actors();
    $base='/api/v1/hotels/'.$fx['hotel']->id.'/night-audit/review';
    $input=['date'=>'2027-01-11','decision'=>'clear',
        'note'=>'Reviewed all listed room-night and cashier preflight exceptions.',
        'acknowledged'=>true];
    Sanctum::actingAs($fx['desk'],['hotel:read','booking:write']);
    $this->postJson($base,$input)->assertForbidden();
    $this->getJson($base.'?date=2027-01-11')->assertForbidden();
    Sanctum::actingAs($fx['manager'],['hotel:read','hotel:write']);
    $this->postJson($base,array_merge($input,['acknowledged'=>false]))->assertUnprocessable();
    $this->postJson($base,array_merge($input,['note'=>'short']))->assertUnprocessable();
    $another=Hotel::create(['name'=>'Other Hotel']);
    $this->postJson('/api/v1/hotels/'.$another->id.'/night-audit/review',$input)->assertNotFound();
    $this->assertDatabaseCount('night_audit_reviews',0);
});

it('initializes an isolated property and advances its clock exactly once after a cleared review', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-24T10:00:00+08:00'));
    config(['financial_accounting.enable_live_folio_tax' => false]);
    $fx = phase58Actors();
    $base = '/api/v1/hotels/'.$fx['hotel']->id;
    $clock = $base.'/finance/business-day';
    $opening = ['businessDate' => '2026-11-23'];
    $otherHotel = Hotel::create(['name' => 'Separate demo property']);
    Sanctum::actingAs($fx['desk'], ['hotel:read', 'hotel:write']);
    $this->postJson($clock.'/initialize', $opening)->assertForbidden();
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $this->postJson('/api/v1/hotels/'.$otherHotel->id.'/finance/business-day/initialize', $opening)->assertNotFound();
    $this->getJson($clock)->assertConflict()->assertJsonPath('code', 'BUSINESS_DATE_NOT_CONFIGURED');
    $this->postJson($clock.'/initialize', $opening)->assertCreated();
    $this->getJson($clock)->assertOk()->assertJsonPath('data.businessDate', '2026-11-23')
        ->assertJsonPath('data.canClose', false)->assertJsonPath('data.version', 0);
    $this->postJson($base.'/night-audit/review', [
        'date' => '2026-11-23', 'decision' => 'clear', 'acknowledged' => true,
        'note' => 'Reviewed the isolated demo property and cleared the preflight.',
    ])->assertCreated();
    $this->getJson($clock)->assertOk()->assertJsonPath('data.canClose', true);
    $close = $opening + ['acknowledged' => true];
    $this->postJson($clock.'/close', $close)->assertOk()->assertJsonPath('data.nextBusinessDate', '2026-11-24');
    $this->postJson($clock.'/close', $close)->assertUnprocessable();
    $this->postJson($clock.'/initialize', $opening)->assertConflict();
    $this->getJson($clock)->assertOk()->assertJsonPath('data.businessDate', '2026-11-24')
        ->assertJsonPath('data.version', 1)->assertJsonPath('data.folioTaxAccountingEnabled', false);
    $this->assertDatabaseCount('financial_day_closes', 1);
    $this->assertDatabaseCount('financial_accounting_periods', 1);
    $this->assertDatabaseMissing('financial_business_clocks', ['hotel_id' => $otherHotel->id]);
});

it('rolls back setup against a closed next period and supports a month-end opening', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-01T10:00:00+08:00'));
    $fx = phase58Actors();
    Sanctum::actingAs($fx['manager'], ['hotel:read', 'hotel:write']);
    $hotelId = $fx['hotel']->id;
    $clock = '/api/v1/hotels/'.$hotelId.'/finance/business-day';
    $opening = ['businessDate' => '2026-11-30'];
    $periods = DB::table('financial_accounting_periods')->where('hotel_id', $hotelId);
    $periods->insert(['hotel_id' => $hotelId, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31', 'status' => 'closed']);
    $this->postJson($clock.'/initialize', $opening)->assertUnprocessable();
    $this->assertDatabaseCount('financial_accounting_periods', 1);
    $this->assertDatabaseCount('financial_business_clocks', 0);
    $periods->update(['status' => 'open']);
    $this->postJson($clock.'/initialize', $opening)->assertCreated();
    $this->assertDatabaseCount('financial_accounting_periods', 2);
    $this->getJson($clock)->assertOk()->assertJsonPath('data.nextBusinessDate', '2026-12-01');
});

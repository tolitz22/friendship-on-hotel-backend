<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('records hotel-scoped staff special requests without booking or price mutation', function (): void {
    $hotel=Hotel::create(['name'=>'Requests Property']);
    $type=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $guest=$hotel->guests()->create(['first_name'=>'Request','last_name'=>'Guest']);
    $staff=User::create([
        'name'=>'Request Reception','email'=>uniqid('req-',true).'@example.test',
        'password'=>'Password123456!',
    ]);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$staff->id,
        'role'=>'front_desk','is_active'=>true]);
    Sanctum::actingAs($staff,['hotel:read','booking:write']);
    $base='/api/v1/hotels/'.$hotel->id;
    $booking=$this->postJson($base.'/bookings',[
        'idempotencyKey'=>'special-book-'.uniqid(),
        'guestId'=>$guest->id,'roomTypeId'=>$type->id,
        'checkIn'=>'2027-01-10','checkOut'=>'2027-01-12',
    ])->assertCreated()->json('data.id');

    $input=[
        'idempotencyKey'=>'special-request-'.uniqid(),
        'tag'=>'quiet_room','note'=>'The guest requested a room away from the elevators.',
    ];
    $url=$base.'/bookings/'.$booking.'/special-requests';
    $id=$this->postJson($url,$input)->assertCreated()
        ->assertJsonPath('data.tag','quiet_room')
        ->assertJsonPath('data.createdBy',(string)$staff->id)
        ->assertJsonPath('data.isGuaranteed',false)
        ->assertJsonPath('data.chargesAutomaticallyPosted',false)
        ->json('data.id');
    $this->postJson($url,$input)->assertOk()->assertJsonPath('data.id',$id);
    $this->postJson($url,array_merge($input,['note'=>'Changed request']))
        ->assertStatus(409);
    $this->getJson($url)->assertOk()->assertJsonPath('meta.total',1)
        ->assertJsonPath('data.0.id',$id);
    $this->assertDatabaseHas('reservation_special_requests',[
        'hotel_id'=>$hotel->id,'reservation_record_id'=>$booking,
        'created_by'=>$staff->id,'tag'=>'quiet_room',
    ]);
    $this->assertDatabaseHas('reservation_records',[
        'hotel_id'=>$hotel->id,'id'=>$booking,
        'lifecycle_state'=>'confirmed',
    ]);
    expect(DB::table('folio_adjustments')->count())->toBe(0);
});

it('rejects foreign hotel bookings and invalid tag or note',function (): void {
    $hotel=Hotel::create(['name'=>'Special A']);
    $other=Hotel::create(['name'=>'Special B']);
    $staff=User::create(['name'=>'Manager','email'=>uniqid('spec-',true).'@example.test',
        'password'=>'Password123456!']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$staff->id,
        'role'=>'admin','is_active'=>true]);
    Sanctum::actingAs($staff,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$hotel->id.'/bookings/999/special-requests';
    $input=['idempotencyKey'=>'special-invalid-01','tag'=>'quiet_room','note'=>'A real request'];
    $this->postJson($base,$input)->assertNotFound();
    $this->postJson($base,array_merge($input,['tag'=>'bad_tag']))->assertUnprocessable();
    $this->postJson($base,array_merge($input,['note'=>'   ']))->assertUnprocessable();
    $this->getJson('/api/v1/hotels/'.$other->id.'/bookings/999/special-requests')
        ->assertNotFound();
});

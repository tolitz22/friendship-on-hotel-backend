<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('returns paginated stays only for the selected hotel and guest', function (): void {
    $hotel = Hotel::create(['name'=>'Guest History A']);
    $other = Hotel::create(['name'=>'Guest History B']);
    $type = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $foreignType = RoomType::create(['hotel_id'=>$other->id,'name'=>'Standard','max_occupancy'=>2]);
    $guest = $hotel->guests()->create(['first_name'=>'Marie','last_name'=>'Santos']);
    $another = $hotel->guests()->create(['first_name'=>'Other','last_name'=>'Guest']);
    $foreignGuest = $other->guests()->create(['first_name'=>'Foreign','last_name'=>'Guest']);
    foreach ([
        [$hotel,$type,$guest,'MARIE-OLD','2027-01-03'],
        [$hotel,$type,$guest,'MARIE-NEW','2027-01-10'],
        [$hotel,$type,$another,'ANOTHER','2027-01-12'],
        [$other,$foreignType,$foreignGuest,'FOREIGN','2027-01-12'],
    ] as [$property,$category,$profile,$reference,$date]) {
        DB::table('reservation_records')->insert([
            'hotel_id'=>$property->id,'room_type_id'=>$category->id,
            'guest_id'=>$profile->id,'reference'=>$reference,
            'check_in'=>$date,'check_out'=>'2027-01-13',
            'lifecycle_state'=>'confirmed',
        ]);
    }
    $staff = User::create([
        'name'=>'Guest Reader','email'=>uniqid('gh-',true).'@example.test',
        'password'=>'Password123456!',
    ]);
    HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$staff->id,'role'=>'admin','is_active'=>true,
    ]);
    Sanctum::actingAs($staff,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$hotel->id.'/guests/'.$guest->id.'/stays';
    $this->getJson($base.'?per_page=1')->assertOk()
        ->assertJsonPath('data.0.reference','MARIE-NEW')
        ->assertJsonPath('meta.total',2)->assertJsonPath('meta.totalPages',2)
        ->assertJsonPath('meta.excludesUnverifiedLifetimeSpend',true);
    $this->getJson($base.'?per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.0.reference','MARIE-OLD');
    $this->getJson($base.'?per_page=100')->assertUnprocessable();
    $this->getJson('/api/v1/hotels/'.$other->id.'/guests/'.$foreignGuest->id.'/stays')
        ->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/guests/'.$foreignGuest->id.'/stays')
        ->assertNotFound();
});

it('requires guest read permission for stay history',function (): void {
    $hotel=Hotel::create(['name'=>'History Restrictions']);
    $guest=$hotel->guests()->create(['first_name'=>'Valid','last_name'=>'Guest']);
    $staff=User::create(['name'=>'Maintenance','email'=>uniqid('hr-',true).'@example.test',
        'password'=>'Password123456!']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$staff->id,
        'role'=>'maintenance','is_active'=>true]);
    Sanctum::actingAs($staff,['hotel:read']);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/guests/'.$guest->id.'/stays')
        ->assertForbidden();
});

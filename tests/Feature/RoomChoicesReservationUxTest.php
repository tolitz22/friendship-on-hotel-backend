<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('offers only matching physical room types, shows overlaps and blocks, and enforces hotel scope', function (): void {
    $hotel = Hotel::create(['name' => 'Room Choice Hotel']);
    $type = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $otherType = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Premium','max_occupancy'=>3]);
    $available = Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'CHOICE-101']);
    $blocked = Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'CHOICE-102']);
    $occupied = Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'CHOICE-103']);
    Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$otherType->id,'number'=>'PREMIUM-999']);
    $guest = $hotel->guests()->create(['first_name'=>'Test','last_name'=>'Guest']);
    $user = User::create([
        'name'=>'Front Desk Choices','email'=>uniqid('choices-',true).'@example.test',
        'password'=>'Password123456!',
    ]);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,
        'role'=>'front_desk','is_active'=>true]);
    Sanctum::actingAs($user, ['hotel:read','booking:write']);
    $base='/api/v1/hotels/'.$hotel->id;
    $booking=$this->postJson($base.'/bookings',[
        'idempotencyKey'=>'choices-booking-'.uniqid(),'guestId'=>$guest->id,
        'roomTypeId'=>$type->id,'checkIn'=>'2027-02-10','checkOut'=>'2027-02-12',
    ])->assertCreated()->json('data.id');
    $other=$this->postJson($base.'/bookings',[
        'idempotencyKey'=>'choices-overlap-'.uniqid(),'guestId'=>$guest->id,
        'roomTypeId'=>$type->id,'checkIn'=>'2027-02-11','checkOut'=>'2027-02-13',
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/bookings/'.$other.'/assign-room',['roomId'=>$occupied->id])->assertOk();

    // A block is inserted by authorized admin in an independent transaction.
    $admin=User::create(['name'=>'Manager','email'=>uniqid('manager-',true).'@example.test','password'=>'Password123456!']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$admin->id,'role'=>'admin','is_active'=>true]);
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $this->postJson($base.'/inventory-blocks',[
        'roomId'=>$blocked->id,'checkIn'=>'2027-02-10','checkOut'=>'2027-02-12',
        'reason'=>'Scheduled physical maintenance',
    ])->assertCreated();

    Sanctum::actingAs($user,['hotel:read','booking:write']);
    $choices=$this->getJson($base.'/bookings/'.$booking.'/room-choices?per_page=10')
        ->assertOk()->assertJsonPath('meta.bookingId',(string)$booking)
        ->assertJsonPath('meta.roomTypeName','Standard')
        ->assertJsonPath('meta.advisoryOnly',true)->json('data');
    expect(count($choices))->toBe(3);
    $byNumber=collect($choices)->keyBy('number');
    expect($byNumber['CHOICE-101']['status'])->toBe('available');
    expect($byNumber['CHOICE-102']['reason'])->toBe('maintenance_block');
    expect($byNumber['CHOICE-103']['reason'])->toBe('reservation_conflict');
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room',['roomId'=>$blocked->id])->assertStatus(409);
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room',['roomId'=>$occupied->id])->assertStatus(409);
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room',['roomId'=>$available->id])->assertOk();
    $this->getJson($base.'/bookings/'.$booking.'/room-choices?per_page=10')
        ->assertOk()->assertJsonPath('meta.currentRoomNumber','CHOICE-101');
    $foreignHotel=Hotel::create(['name'=>'Other Hotel']);
    $this->getJson('/api/v1/hotels/'.$foreignHotel->id.'/bookings/'.$booking.'/room-choices')
        ->assertNotFound();
});

it('registers Phase 51 approval and operational exception API GET routes', function (): void {
    $routes=collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes());
    foreach(['api/v1/hotels/{hotel}/approval-requests',
        'api/v1/hotels/{hotel}/operational-exceptions',
        'api/v1/hotels/{hotel}/bookings/{booking}/room-choices'] as $path) {
        expect($routes->contains(fn ($route) => $route->uri()===$path
            && in_array('GET',$route->methods(),true)))->toBeTrue();
    }
});

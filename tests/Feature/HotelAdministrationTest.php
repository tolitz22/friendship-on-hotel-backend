<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('allows property admins to configure contact details and room catalog', function (): void {
    $admin = User::create(['name'=>'Admin','email'=>'owner@example.test','password'=>'safe-example-password']);
    $hotel = Hotel::create(['name'=>'Demo']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$admin->id,'role'=>'admin']);
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);

    $this->patchJson('/api/v1/hotels/'.$hotel->id, [
        'name'=>'Updated Demo','address'=>'Example Test Address','contactEmail'=>'team@example.test'
    ])->assertOk()->assertJsonPath('data.name','Updated Demo');

    $type = $this->postJson('/api/v1/hotels/'.$hotel->id.'/room-types', [
        'name'=>'Standard','maxOccupancy'=>2,
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/hotels/'.$hotel->id.'/rooms', [
        'roomTypeId'=>$type['id'],'number'=>'201','floor'=>2,
    ])->assertCreated()->assertJsonPath('data.roomTypeName','Standard');
});

it('rejects cross-property room type assignment and duplicates', function (): void {
    $admin = User::create(['name'=>'Admin','email'=>'owner2@example.test','password'=>'safe-example-password']);
    $first = Hotel::create(['name'=>'Hotel One']);
    $second = Hotel::create(['name'=>'Hotel Two']);
    HotelMembership::create(['hotel_id'=>$first->id,'user_id'=>$admin->id,'role'=>'admin']);
    $type = RoomType::create(['hotel_id'=>$second->id,'name'=>'Different','max_occupancy'=>2]);
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);

    $this->postJson('/api/v1/hotels/'.$first->id.'/rooms', [
        'roomTypeId'=>$type->id,'number'=>'101','floor'=>1,
    ])->assertUnprocessable();

    $this->postJson('/api/v1/hotels/'.$first->id.'/room-types', [
        'name'=>'Standard','maxOccupancy'=>2,
    ])->assertCreated();
    $this->postJson('/api/v1/hotels/'.$first->id.'/room-types', [
        'name'=>'Standard','maxOccupancy'=>2,
    ])->assertUnprocessable();
});

it('denies writes without hotel admin membership or a write-capable token', function (): void {
    $user = User::create(['name'=>'Desk','email'=>'desk@example.test','password'=>'safe-example-password']);
    $hotel = Hotel::create(['name'=>'Demo']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>'front_desk']);
    Sanctum::actingAs($user,['hotel:read']);

    $this->postJson('/api/v1/hotels/'.$hotel->id.'/room-types', [
        'name'=>'Standard','maxOccupancy'=>2,
    ])->assertForbidden();
});

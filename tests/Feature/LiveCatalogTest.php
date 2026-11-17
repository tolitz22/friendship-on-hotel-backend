<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function catalogStaff(Hotel $hotel): void
{
    $user = User::create([
        'name' => 'Catalog Staff',
        'email' => 'catalog-'.uniqid().'@example.test',
        'password' => 'example-password',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id,
        'user_id' => $user->id,
        'role' => 'front_desk',
        'is_active' => true,
    ]);
    Sanctum::actingAs($user, ['hotel:read']);
}

it('returns hotel-scoped physical room counts and type/floor groups, not occupancy', function (): void {
    $hotel = Hotel::create(['name' => 'Catalogue Hotel']);
    $other = Hotel::create(['name' => 'Other Property']);
    $standard = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $suite = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Suite','max_occupancy'=>4]);
    $external = RoomType::create(['hotel_id'=>$other->id,'name'=>'Standard','max_occupancy'=>2]);
    Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$standard->id,'number'=>'101','floor_number'=>1]);
    Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$standard->id,'number'=>'102','floor_number'=>1]);
    Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$suite->id,'number'=>'201','floor_number'=>2]);
    Room::create(['hotel_id'=>$other->id,'room_type_id'=>$external->id,'number'=>'999','floor_number'=>9]);

    catalogStaff($hotel);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/catalog-summary')
        ->assertOk()
        ->assertJsonPath('data.hotelId', (string) $hotel->id)
        ->assertJsonPath('data.totalRooms', 3)
        ->assertJsonPath('data.totalRoomTypes', 2)
        ->assertJsonCount(2, 'data.floors')
        ->assertJsonPath('data.floors.0.number', 1)
        ->assertJsonPath('data.floors.0.roomCount', 2)
        ->assertJsonPath('meta.isAvailability', false)
        ->assertJsonPath('meta.isOccupancy', false)
        ->assertJsonMissingPath('data.occupiedRooms');

    $this->getJson('/api/v1/hotels/'.$other->id.'/catalog-summary')->assertNotFound();
});

it('validates and scopes live room catalogue filters', function (): void {
    $hotel = Hotel::create(['name' => 'Filtered Hotel']);
    $type = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'101','floor_number'=>1]);
    Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'201','floor_number'=>2]);
    catalogStaff($hotel);
    $base = '/api/v1/hotels/'.$hotel->id.'/rooms';

    $this->getJson($base.'?floor=1')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '101');
    $this->getJson($base.'?search=201')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '201');
    $this->getJson($base.'?search=%25')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($base.'?floor=abc')->assertUnprocessable();
    $this->getJson($base.'?per_page=101')->assertUnprocessable();
});

it('requires login for catalogue summary', function (): void {
    $hotel = Hotel::create(['name' => 'Protected Catalogue']);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/catalog-summary')->assertUnauthorized();
});

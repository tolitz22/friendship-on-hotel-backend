<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('requires authentication for property data', function (): void {
    $hotel = Hotel::create(['name' => 'Demo Property']);

    $this->getJson('/api/v1/hotels/'.$hotel->id)->assertUnauthorized();
});

it('isolates rooms to a property and its active members', function (): void {
    $user = User::create(['name' => 'Agent', 'email' => 'agent@example.test', 'password' => 'long-passphrase']);
    $one = Hotel::create(['name' => 'Hotel A']);
    $two = Hotel::create(['name' => 'Hotel B']);

    HotelMembership::create(['hotel_id' => $one->id, 'user_id' => $user->id, 'role' => 'front_desk', 'is_active' => true]);

    $typeOne = RoomType::create(['hotel_id' => $one->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $typeTwo = RoomType::create(['hotel_id' => $two->id, 'name' => 'Suite', 'max_occupancy' => 4]);
    Room::create(['hotel_id' => $one->id, 'room_type_id' => $typeOne->id, 'number' => '101', 'floor_number' => 1]);
    Room::create(['hotel_id' => $two->id, 'room_type_id' => $typeTwo->id, 'number' => '201', 'floor_number' => 2]);

    Sanctum::actingAs($user, ['hotel:read']);

    $this->getJson('/api/v1/hotels/'.$one->id.'/rooms')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', '101');
    $this->getJson('/api/v1/hotels/'.$two->id.'/rooms')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$two->id.'/room-types')->assertNotFound();
});

it('revokes property access for inactive membership', function (): void {
    $user = User::create(['name' => 'Agent', 'email' => 'agent2@example.test', 'password' => 'long-passphrase']);
    $hotel = Hotel::create(['name' => 'Hotel A']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'front_desk', 'is_active' => false]);

    Sanctum::actingAs($user, ['hotel:read']);

    $this->getJson('/api/v1/hotels/'.$hotel->id)->assertNotFound();
});

it('makes room endpoints read only', function (): void {
    $user = User::create(['name' => 'Agent', 'email' => 'agent3@example.test', 'password' => 'long-passphrase']);
    $hotel = Hotel::create(['name' => 'Hotel A']);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'front_desk']);
    Sanctum::actingAs($user, ['hotel:read']);

    $this->postJson('/api/v1/hotels/'.$hotel->id.'/rooms', ['number' => '401'])->assertForbidden();
});


it('treats newly created staff as active before reloading the database default', function (): void {
    $user = User::create([
        'name' => 'Default Active',
        'email' => 'default-active@example.test',
        'password' => 'long-passphrase',
    ]);
    $hotel = Hotel::create(['name' => 'Default Active Hotel']);
    HotelMembership::create([
        'hotel_id' => $hotel->id,
        'user_id' => $user->id,
        'role' => 'front_desk',
    ]);

    // Sanctum uses this same in-memory model instance.
    expect($user->is_active)->toBeTrue();
    Sanctum::actingAs($user, ['hotel:read']);

    $this->getJson('/api/v1/hotels/'.$hotel->id)
        ->assertOk()
        ->assertJsonPath('data.name', 'Default Active Hotel');
});

it('denies a deliberately inactive user even when they hold an active hotel membership', function (): void {
    $user = User::create([
        'name' => 'Inactive Agent',
        'email' => 'inactive-agent@example.test',
        'password' => 'long-passphrase',
        'is_active' => false,
    ]);
    $hotel = Hotel::create(['name' => 'Inactive Account Hotel']);
    HotelMembership::create([
        'hotel_id' => $hotel->id,
        'user_id' => $user->id,
        'role' => 'front_desk',
    ]);

    Sanctum::actingAs($user, ['hotel:read']);

    $this->getJson('/api/v1/hotels/'.$hotel->id)->assertForbidden();
});

it('allows managers to edit hotel profile details with auditable changes', function (): void {
    $manager = User::create([
        'name'=>'Property Manager','email'=>'property-manager@example.test','password'=>'long-passphrase',
    ]);
    $hotel = Hotel::create(['name'=>'Original Hotel']);
    HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$manager->id,'role'=>'admin','is_active'=>true,
    ]);
    Sanctum::actingAs($manager, ['hotel:read','hotel:write']);
    $url='/api/v1/hotels/'.$hotel->id;
    $this->patchJson($url,[
        'name'=>'Updated Hotel','address'=>'Highway 12, Pampanga',
        'contactEmail'=>'desk@example.test','contactPhone'=>'+63 123 456 7890',
    ])->assertOk()->assertJsonPath('data.name','Updated Hotel')
        ->assertJsonPath('data.contactEmail','desk@example.test')
        ->assertJsonPath('data.currency','PHP');

    $entry=\Illuminate\Support\Facades\DB::table('catalog_audit_logs')
        ->where('hotel_id',$hotel->id)->where('subject_type','hotel')->first();
    expect($entry)->not->toBeNull();
    expect($entry->actor_id)->toBe($manager->id);
    expect(json_decode($entry->changes_before,true)['name'])->toBe('Original Hotel');
    expect(json_decode($entry->changes_after,true)['name'])->toBe('Updated Hotel');

    $this->patchJson($url,['name'=>'Updated Hotel'])->assertOk();
    expect(\Illuminate\Support\Facades\DB::table('catalog_audit_logs')
        ->where('hotel_id',$hotel->id)->where('subject_type','hotel')->count())->toBe(1);
});

it('blocks non-managers from changing property details and forbids currency or timezone edits', function (): void {
    $worker=User::create([
        'name'=>'Property Worker','email'=>'property-worker@example.test','password'=>'long-passphrase',
    ]);
    $manager=User::create([
        'name'=>'Safe Manager','email'=>'safe-manager@example.test','password'=>'long-passphrase',
    ]);
    $hotel=Hotel::create(['name'=>'Protected Hotel','timezone'=>'Asia/Manila','currency'=>'PHP']);
    foreach ([[$worker,'front_desk'],[$manager,'admin']] as [$user,$role]) {
        HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>$role]);
    }
    $url='/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($worker,['hotel:read','booking:write']);
    $this->patchJson($url,['name'=>'Unauthorized'])->assertForbidden();
    Sanctum::actingAs($manager,['hotel:read','hotel:write']);
    $this->patchJson($url,['name'=>'  '])->assertUnprocessable();
    $this->patchJson($url,['address'=>'Office','currency'=>'USD','timeZone'=>'UTC'])
        ->assertOk()->assertJsonPath('data.currency','PHP')->assertJsonPath('data.timeZone','Asia/Manila');
});

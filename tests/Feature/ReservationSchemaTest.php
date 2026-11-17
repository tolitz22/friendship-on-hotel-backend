<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;

it('creates a hotel-scoped reservation record with optional physical assignment', function (): void {
    $hotel = Hotel::create(['name' => 'Sample Property']);
    $type = RoomType::create(['hotel_id'=>$hotel->id, 'name'=>'Standard', 'max_occupancy'=>2]);

    $id = DB::table('reservation_records')->insertGetId([
        'hotel_id'=>$hotel->id, 'room_type_id'=>$type->id,
        'reference'=>'TEST-1', 'assigned_room_id'=>null,
        'check_in'=>'2026-11-17', 'check_out'=>'2026-11-19',
        'created_at'=>now(), 'updated_at'=>now(),
    ]);

    expect(DB::table('reservation_records')->find($id)->assigned_room_id)->toBeNull();
});

it('refuses physical room assignment from a different hotel', function (): void {
    $a = Hotel::create(['name' => 'Property A']);
    $b = Hotel::create(['name' => 'Property B']);
    $typeA = RoomType::create(['hotel_id'=>$a->id,'name'=>'Standard','max_occupancy'=>2]);
    $typeB = RoomType::create(['hotel_id'=>$b->id,'name'=>'Suite','max_occupancy'=>4]);
    $roomB = Room::create(['hotel_id'=>$b->id,'room_type_id'=>$typeB->id,'number'=>'202']);

    expect(fn () => DB::table('reservation_records')->insert([
        'hotel_id'=>$a->id,'room_type_id'=>$typeA->id,
        'reference'=>'CROSS-HOTEL','assigned_room_id'=>$roomB->id,
        'check_in'=>'2026-11-17','check_out'=>'2026-11-19',
        'created_at'=>now(),'updated_at'=>now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('does not expose reservation mutations over the API', function (): void {
    $this->postJson('/api/v1/reservations', [
        'checkIn'=>'2026-11-17','checkOut'=>'2026-11-19',
    ])->assertNotFound();
});


it('rejects an assigned room of another room type within the same hotel', function (): void {
    $hotel = Hotel::create(['name' => 'Example Hotel']);
    $standard = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $suite = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Suite','max_occupancy'=>4]);
    $suiteRoom = Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$suite->id,'number'=>'501']);

    expect(fn () => DB::table('reservation_records')->insert([
        'hotel_id'=>$hotel->id,'room_type_id'=>$standard->id,
        'reference'=>'WRONG-TYPE','assigned_room_id'=>$suiteRoom->id,
        'check_in'=>'2026-11-17','check_out'=>'2026-11-19',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('enforces positive stay dates at the database layer', function (): void {
    $hotel = Hotel::create(['name' => 'Example Hotel']);
    $type = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);

    expect(fn () => DB::table('reservation_records')->insert([
        'hotel_id'=>$hotel->id,'room_type_id'=>$type->id,
        'reference'=>'ZERO-NIGHTS','check_in'=>'2026-11-19','check_out'=>'2026-11-19',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('returns scoped structural records without implying room availability', function (): void {
    $hotel = Hotel::create(['name'=>'Hotel A']);
    $other = Hotel::create(['name'=>'Hotel B']);
    $type = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $otherType = RoomType::create(['hotel_id'=>$other->id,'name'=>'Standard','max_occupancy'=>2]);

    DB::table('reservation_records')->insert([
        ['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'reference'=>'LOCAL',
         'check_in'=>'2026-11-17','check_out'=>'2026-11-19'],
        ['hotel_id'=>$other->id,'room_type_id'=>$otherType->id,'reference'=>'OTHER',
         'check_in'=>'2026-11-17','check_out'=>'2026-11-19'],
    ]);

    $staff = \App\Models\User::create([
        'name'=>'Desk','email'=>'desk-reservation@example.test','password'=>'example-password',
    ]);
    \App\Models\HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$staff->id,'role'=>'front_desk',
    ]);
    \Laravel\Sanctum\Sanctum::actingAs($staff, ['hotel:read']);

    $this->getJson('/api/v1/hotels/'.$hotel->id.'/reservation-records')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'LOCAL')
        ->assertJsonPath('meta.isAvailability', false);

    $this->getJson('/api/v1/hotels/'.$other->id.'/reservation-records')
        ->assertNotFound();
});

it('requires authentication for structural reservation records', function (): void {
    $hotel = Hotel::create(['name'=>'Protected Hotel']);

    $this->getJson('/api/v1/hotels/'.$hotel->id.'/reservation-records')
        ->assertUnauthorized();
});

it('has no public or authenticated reservation creation endpoint', function (): void {
    $hotel = Hotel::create(['name'=>'Read Only Hotel']);
    $admin = \App\Models\User::create([
        'name'=>'Admin','email'=>'admin-reservation@example.test','password'=>'example-password',
    ]);
    \App\Models\HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$admin->id,'role'=>'admin',
    ]);
    \Laravel\Sanctum\Sanctum::actingAs($admin, ['hotel:read','hotel:write']);

    $this->postJson('/api/v1/hotels/'.$hotel->id.'/reservation-records', [
        'reference'=>'NOT-BOOKED',
    ])->assertStatus(405);

    expect(DB::table('reservation_records')->count())->toBe(0);
});

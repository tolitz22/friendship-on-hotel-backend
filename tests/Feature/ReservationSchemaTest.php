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

<?php

declare(strict_types=1);

use App\Domain\Reservations\ReservationScenarioAnalyzer;
use App\Domain\Reservations\StayPeriod;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function scenarioStaff(Hotel $hotel): void
{
    $user=User::create(['name'=>'Scenario Staff','email'=>'scenario-'.uniqid().'@example.test','password'=>'test-password']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>'front_desk','is_active'=>true]);
    Sanctum::actingAs($user,['hotel:read']);
}

it('uses half-open nights and separates room overlaps from unassigned type records', function (): void {
    $scenario=(new ReservationScenarioAnalyzer())->analyze(
        new StayPeriod('2026-11-19','2026-11-21'),'1','10',[
            ['id'=>'1','reference'=>'EARLY','roomTypeId'=>'1','assignedRoomId'=>'10','checkIn'=>'2026-11-17','checkOut'=>'2026-11-19','lifecycleState'=>'confirmed'],
            ['id'=>'2','reference'=>'OVERLAP','roomTypeId'=>'1','assignedRoomId'=>'10','checkIn'=>'2026-11-20','checkOut'=>'2026-11-22','lifecycleState'=>'cancelled'],
            ['id'=>'3','reference'=>'UNASSIGNED','roomTypeId'=>'1','assignedRoomId'=>null,'checkIn'=>'2026-11-19','checkOut'=>'2026-11-20','lifecycleState'=>null],
            ['id'=>'4','reference'=>'OTHER ROOM','roomTypeId'=>'1','assignedRoomId'=>'11','checkIn'=>'2026-11-19','checkOut'=>'2026-11-21','lifecycleState'=>'confirmed'],
            ['id'=>'5','reference'=>'OTHER TYPE','roomTypeId'=>'2','assignedRoomId'=>null,'checkIn'=>'2026-11-19','checkOut'=>'2026-11-21','lifecycleState'=>'confirmed'],
        ]
    );

    expect($scenario['nights'])->toBe(2);
    expect(array_column($scenario['assignedRoomOverlaps'],'reference'))->toBe(['OVERLAP']);
    expect(array_column($scenario['unassignedTypeOverlaps'],'reference'))->toBe(['UNASSIGNED']);
    expect($scenario['otherAssignedSameTypeCount'])->toBe(1);
    expect($scenario['hasAssignedRoomOverlap'])->toBeTrue();
});

it('protects room ownership and reports real structural records without claiming bookability', function (): void {
    $a=Hotel::create(['name'=>'Simulator A']);
    $b=Hotel::create(['name'=>'Simulator B']);
    $type=RoomType::create(['hotel_id'=>$a->id,'name'=>'Standard','max_occupancy'=>2]);
    $otherType=RoomType::create(['hotel_id'=>$b->id,'name'=>'Standard','max_occupancy'=>2]);
    $room=Room::create(['hotel_id'=>$a->id,'room_type_id'=>$type->id,'number'=>'101']);
    $otherRoom=Room::create(['hotel_id'=>$b->id,'room_type_id'=>$otherType->id,'number'=>'999']);
    DB::table('reservation_records')->insert([
        ['hotel_id'=>$a->id,'room_type_id'=>$type->id,'assigned_room_id'=>$room->id,
         'reference'=>'CANCELLED','check_in'=>'2026-11-18','check_out'=>'2026-11-21','lifecycle_state'=>'cancelled'],
        ['hotel_id'=>$a->id,'room_type_id'=>$type->id,'assigned_room_id'=>null,
         'reference'=>'TYPE DEMAND','check_in'=>'2026-11-19','check_out'=>'2026-11-21'],
        ['hotel_id'=>$b->id,'room_type_id'=>$otherType->id,'assigned_room_id'=>$otherRoom->id,
         'reference'=>'PRIVATE','check_in'=>'2026-11-19','check_out'=>'2026-11-21'],
    ]);
    $base='/api/v1/hotels/'.$a->id.'/reservation-simulation';
    $query='?roomId='.$room->id.'&from=2026-11-19&to=2026-11-22';
    $this->getJson($base.$query)->assertUnauthorized();
    scenarioStaff($a);

    $this->getJson($base.$query)->assertOk()
        ->assertJsonPath('data.roomNumber','101')
        ->assertJsonPath('data.nights',3)
        ->assertJsonPath('data.assignedRoomOverlaps.0.reference','CANCELLED')
        ->assertJsonPath('data.unassignedTypeOverlaps.0.reference','TYPE DEMAND')
        ->assertJsonCount(1,'data.assignedRoomOverlaps')
        ->assertJsonPath('meta.isBookable',false)
        ->assertJsonPath('meta.isAvailability',false)
        ->assertJsonPath('meta.complete',true);

    $this->getJson('/api/v1/hotels/'.$b->id.'/reservation-simulation'.$query)->assertNotFound();
    $this->getJson($base.'?roomId='.$otherRoom->id.'&from=2026-11-19&to=2026-11-22')->assertNotFound();
});

it('enforces valid 1 to 31 night dates and never enables reservation writes', function (): void {
    $hotel=Hotel::create(['name'=>'Scenario Validation']);
    $type=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $room=Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'101']);
    scenarioStaff($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/reservation-simulation?roomId='.$room->id;
    $this->getJson($base.'&from=2026-11-20&to=2026-11-20')->assertUnprocessable();
    $this->getJson($base.'&from=2026-11-20&to=2027-01-01')->assertUnprocessable();
    $this->getJson($base.'&from=2026-02-30&to=2026-03-03')->assertUnprocessable();
    $this->getJson($base.'&from=2026-11-20&to=2026-11-21')->assertOk()
        ->assertJsonPath('data.hasAssignedRoomOverlap',false)
        ->assertJsonPath('meta.isBookable',false);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/reservation-records',['reference'=>'NO BOOKING'])->assertStatus(405);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/reservation-simulation',[])->assertStatus(405);
});

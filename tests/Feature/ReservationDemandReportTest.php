<?php

declare(strict_types=1);

use App\Domain\Reservations\ReservationDemandAnalyzer;
use App\Domain\Reservations\StayPeriod;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('counts half-open reservation nights by room type and assignment without claiming vacancy', function (): void {
    $analyzer = new ReservationDemandAnalyzer();
    $days = $analyzer->analyze(
        new StayPeriod('2026-11-17', '2026-11-21'),
        [
            ['roomTypeId'=>'1', 'assignedRoomId'=>'11', 'checkIn'=>'2026-11-16', 'checkOut'=>'2026-11-19'],
            ['roomTypeId'=>'1', 'assignedRoomId'=>null, 'checkIn'=>'2026-11-18', 'checkOut'=>'2026-11-21'],
            ['roomTypeId'=>'1', 'assignedRoomId'=>'11', 'checkIn'=>'2026-11-19', 'checkOut'=>'2026-11-22'],
            ['roomTypeId'=>'2', 'assignedRoomId'=>null, 'checkIn'=>'2026-11-20', 'checkOut'=>'2026-11-22'],
        ],
        [
            ['id'=>'1','name'=>'Standard','registeredRooms'=>2],
            ['id'=>'2','name'=>'Suite','registeredRooms'=>1],
        ]
    );

    expect(array_column($days, 'date'))->toBe([
        '2026-11-17','2026-11-18','2026-11-19','2026-11-20',
    ]);
    expect($days[0]['roomTypes'][0]['totalRecords'])->toBe(1);
    expect($days[1]['roomTypes'][0]['totalRecords'])->toBe(2);
    expect($days[1]['roomTypes'][0]['unassignedRecords'])->toBe(1);
    expect($days[2]['roomTypes'][0]['totalRecords'])->toBe(2);
    expect($days[2]['roomTypes'][0]['assignedRecords'])->toBe(1);
    expect($days[3]['roomTypes'][0]['totalRecords'])->toBe(2);
    expect($days[3]['roomTypes'][1]['unassignedRecords'])->toBe(1);
});

function demandReportLogin(Hotel $hotel): void
{
    $staff = User::create([
        'name' => 'Demand Inspector',
        'email' => 'demand-'.uniqid().'@example.test',
        'password' => 'test-password',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id,
        'user_id' => $staff->id,
        'role' => 'front_desk',
        'is_active' => true,
    ]);
    Sanctum::actingAs($staff, ['hotel:read']);
}

it('reports only the selected hotel records and includes unassigned demand', function (): void {
    $hotel = Hotel::create(['name' => 'Demand Hotel']);
    $other = Hotel::create(['name' => 'Foreign Hotel']);
    $type = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $otherType = RoomType::create(['hotel_id'=>$other->id,'name'=>'Suite','max_occupancy'=>4]);
    $room = Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'101']);
    DB::table('reservation_records')->insert([
        ['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'assigned_room_id'=>$room->id,
            'reference'=>'ASSIGNED','check_in'=>'2026-11-17','check_out'=>'2026-11-19','lifecycle_state'=>'cancelled'],
        ['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'assigned_room_id'=>null,
            'reference'=>'UNASSIGNED','check_in'=>'2026-11-18','check_out'=>'2026-11-20','lifecycle_state'=>null],
        ['hotel_id'=>$other->id,'room_type_id'=>$otherType->id,'assigned_room_id'=>null,
            'reference'=>'OTHER','check_in'=>'2026-11-18','check_out'=>'2026-11-20','lifecycle_state'=>null],
    ]);
    demandReportLogin($hotel);
    $url = '/api/v1/hotels/'.$hotel->id.'/reservation-demand?from=2026-11-17&to=2026-11-21';
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.matchingRecordCount', 2)
        ->assertJsonPath('data.days.1.roomTypes.0.totalRecords', 2)
        ->assertJsonPath('data.days.1.roomTypes.0.assignedRecords', 1)
        ->assertJsonPath('data.days.1.roomTypes.0.unassignedRecords', 1)
        ->assertJsonPath('meta.isAvailability', false)
        ->assertJsonPath('meta.isOccupancy', false)
        ->assertJsonPath('meta.complete', true)
        ->assertJsonPath('meta.scope', 'structural_reservation_demand_all_states');

    $this->getJson('/api/v1/hotels/'.$other->id.'/reservation-demand?from=2026-11-17&to=2026-11-21')
        ->assertNotFound();
});

it('rejects invalid reporting windows', function (): void {
    $hotel = Hotel::create(['name'=>'Window Hotel']);
    demandReportLogin($hotel);
    $url = '/api/v1/hotels/'.$hotel->id.'/reservation-demand';
    $this->getJson($url.'?from=2026-11-21&to=2026-11-17')->assertUnprocessable();
    $this->getJson($url.'?from=2026-11-17&to=2027-01-01')->assertUnprocessable();
    $this->getJson($url.'?from=2026-02-30&to=2026-03-02')->assertUnprocessable();
});

it('denies unauthenticated reporting requests and provides empty structural results', function (): void {
    $hotel = Hotel::create(['name'=>'Empty Demand Hotel']);
    $url='/api/v1/hotels/'.$hotel->id.'/reservation-demand?from=2026-11-17&to=2026-11-20';
    $this->getJson($url)->assertUnauthorized();
    demandReportLogin($hotel);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.matchingRecordCount',0)
        ->assertJsonCount(3,'data.days')
        ->assertJsonPath('meta.isAvailability',false);
});

it('keeps reservation write routes unavailable', function (): void {
    $hotel = Hotel::create(['name'=>'No Booking Hotel']);
    demandReportLogin($hotel);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/reservation-demand', [
        'checkIn'=>'2026-11-17','checkOut'=>'2026-11-19',
    ])->assertStatus(405);
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/reservation-records', [
        'reference'=>'NOT-BOOKED',
    ])->assertStatus(405);
});

<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function maintenanceLogin(Hotel $hotel, string $role='admin', array $abilities=['hotel:read','hotel:write']): void
{
    $user=User::create([
        'name'=>'Maintenance Staff','email'=>'maintenance-'.uniqid().'@example.test',
        'password'=>'test-password',
    ]);
    HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>$role,'is_active'=>true,
    ]);
    Sanctum::actingAs($user,$abilities);
}

function maintenanceRoom(Hotel $hotel): Room
{
    $type=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    return Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'101']);
}

it('creates and reads hotel-scoped tickets with a durable creation event', function (): void {
    $hotel=Hotel::create(['name'=>'Maintenance Hotel']);
    $room=maintenanceRoom($hotel);
    maintenanceLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/maintenance-work-orders';
    $created=$this->postJson($base,[
        'roomId'=>(string)$room->id,'title'=>'Air conditioning repair',
        'description'=>'The AC compressor is making a loud noise.',
        'priority'=>'urgent','assignedTo'=>'Engineer A','affectsRoom'=>true,
        'note'=>'Guest complaint sent to front desk.',
    ])->assertCreated()
       ->assertJsonPath('data.status','open')
       ->assertJsonPath('data.revision',1)
       ->assertJsonPath('data.affectsRoom',true)->json('data');

    $this->getJson($base)->assertOk()->assertJsonCount(1,'data')
        ->assertJsonPath('meta.affectsRoomDoesNotBlockInventory',true);
    $this->getJson($base.'/'.$created['id'])->assertOk()
        ->assertJsonPath('data.title','Air conditioning repair');
    $this->getJson($base.'/'.$created['id'].'/history')->assertOk()
        ->assertJsonCount(1,'data')
        ->assertJsonPath('data.0.action','created')
        ->assertJsonPath('data.0.note','Guest complaint sent to front desk.');
    expect(DB::table('maintenance_work_order_events')->count())->toBe(1);
    expect(DB::table('reservation_records')->count())->toBe(0);
});

it('restricts cross-property room creation and work order reads', function (): void {
    $mine=Hotel::create(['name'=>'Maintenance A']);
    $other=Hotel::create(['name'=>'Maintenance B']);
    maintenanceRoom($mine);
    $foreignRoom=maintenanceRoom($other);
    maintenanceLogin($mine);
    $url='/api/v1/hotels/'.$mine->id.'/maintenance-work-orders';
    $this->postJson($url,[
        'roomId'=>(string)$foreignRoom->id,'title'=>'Wrong hotel','description'=>'Invalid tenant room',
        'priority'=>'low',
    ])->assertUnprocessable()->assertJsonValidationErrors(['roomId']);
    expect(DB::table('maintenance_work_orders')->count())->toBe(0);
    $this->getJson('/api/v1/hotels/'.$other->id.'/maintenance-work-orders')->assertNotFound();
});

it('enforces administrator writes but lets hotel members read tickets', function (): void {
    $hotel=Hotel::create(['name'=>'Maintenance Roles']);
    $room=maintenanceRoom($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/maintenance-work-orders';
    $body=['roomId'=>(string)$room->id,'title'=>'Light','description'=>'Broken lighting','priority'=>'low'];
    $this->getJson($base)->assertUnauthorized();
    $this->postJson($base,$body)->assertUnauthorized();
    maintenanceLogin($hotel,'front_desk',['hotel:read','hotel:write']);
    $this->getJson($base)->assertOk();
    $this->postJson($base,$body)->assertForbidden();
    maintenanceLogin($hotel,'admin',['hotel:read']);
    $this->postJson($base,$body)->assertForbidden();
    expect(DB::table('maintenance_work_orders')->count())->toBe(0);
});

it('enforces valid lifecycle transitions, revision conflicts, and audit events', function (): void {
    $hotel=Hotel::create(['name'=>'Maintenance Lifecycle']);
    $room=maintenanceRoom($hotel);
    maintenanceLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/maintenance-work-orders';
    $id=$this->postJson($base,[
        'roomId'=>(string)$room->id,'title'=>'Water leak','description'=>'Leak at bathroom sink',
        'priority'=>'high',
    ])->assertCreated()->json('data.id');

    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'closed'])
        ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'in_progress','note'=>'Assigned'])
        ->assertOk()->assertJsonPath('data.revision',2)
        ->assertJsonPath('data.status','in_progress');
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'resolved'])
        ->assertStatus(409);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>2,'status'=>'resolved'])
        ->assertOk()->assertJsonPath('data.status','resolved')
        ->assertJsonPath('data.revision',3);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>3,'status'=>'closed'])
        ->assertOk()->assertJsonPath('data.status','closed')
        ->assertJsonPath('data.revision',4);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>4,'status'=>'open'])
        ->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->getJson($base.'/'.$id.'/history')->assertOk()->assertJsonCount(4,'data');
    expect(DB::table('maintenance_work_orders')->where('hotel_id',$hotel->id)->first()->status)->toBe('closed');
});

it('never turns flagged maintenance into availability or changes reservations', function (): void {
    $hotel=Hotel::create(['name'=>'Maintenance Inventory Safety']);
    $room=maintenanceRoom($hotel);
    maintenanceLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/maintenance-work-orders';
    $id=$this->postJson($base,[
        'roomId'=>(string)$room->id,'title'=>'Plumbing','description'=>'Leak under sink',
        'priority'=>'medium','affectsRoom'=>true,
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/'.$id,[
        'expectedRevision'=>1,'status'=>'resolved','affectsRoom'=>false,
    ])->assertOk();
    expect(DB::table('reservation_records')->count())->toBe(0);
    expect(DB::table('rooms')->where('id',$room->id)->first()->number)->toBe('101');
    expect(DB::table('maintenance_work_order_events')->where('work_order_id',$id)->count())->toBe(2);
});

it('rejects invalid ticket priorities, blank descriptions, and invalid page sizes', function (): void {
    $hotel=Hotel::create(['name'=>'Maintenance Validation']);
    $room=maintenanceRoom($hotel);
    maintenanceLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/maintenance-work-orders';
    $this->postJson($base,[
        'roomId'=>(string)$room->id,'title'=>'Repair','description'=>'Work',
        'priority'=>'critical',
    ])->assertUnprocessable()->assertJsonValidationErrors(['priority']);
    $this->postJson($base,[
        'roomId'=>(string)$room->id,'title'=>'Repair','description'=>'    ',
        'priority'=>'high',
    ])->assertUnprocessable()->assertJsonValidationErrors(['description']);
    $this->getJson($base.'?per_page=101')->assertUnprocessable();
});

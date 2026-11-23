<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function housekeepingLogin(Hotel $hotel, string $role='admin', array $abilities=['hotel:read','hotel:write']): void
{
    $user=User::create(['name'=>'Housekeeping Tester','email'=>'hk-'.uniqid().'@example.test','password'=>'test-password']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>$role,'is_active'=>true]);
    Sanctum::actingAs($user,$abilities);
}

function housekeepingRoom(Hotel $hotel): Room
{
    $type=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    return Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'101']);
}

it('creates a hotel-scoped cleaning task with an audit event', function (): void {
    $hotel=Hotel::create(['name'=>'Housekeeping A']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $url='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $data=$this->postJson($url,[
        'roomId'=>(string)$room->id,'type'=>'checkout_clean',
        'assignedTo'=>'Staff A','dueAt'=>'2026-11-20 14:00:00','note'=>'Clean after departure',
    ])->assertCreated()->assertJsonPath('data.status','todo')
        ->assertJsonPath('data.revision',1)->json('data');

    $this->getJson($url)->assertOk()->assertJsonCount(1,'data')
        ->assertJsonPath('meta.determinesRoomReadiness',false);
    $this->getJson($url.'/'.$data['id'])->assertOk()->assertJsonPath('data.roomNumber','101');
    $this->getJson($url.'/'.$data['id'].'/history')->assertOk()
        ->assertJsonCount(1,'data')->assertJsonPath('data.0.action','created')
        ->assertJsonPath('data.0.note','Clean after departure');
    expect(DB::table('housekeeping_task_events')->count())->toBe(1);
});

it('rejects foreign-room tasks and foreign-hotel reads', function (): void {
    $mine=Hotel::create(['name'=>'Housekeeping Mine']);
    $other=Hotel::create(['name'=>'Housekeeping Other']);
    housekeepingRoom($mine);
    $foreign=housekeepingRoom($other);
    housekeepingLogin($mine);
    $base='/api/v1/hotels/'.$mine->id.'/housekeeping-tasks';
    $this->postJson($base,['roomId'=>(string)$foreign->id,'type'=>'inspection'])
        ->assertUnprocessable()->assertJsonValidationErrors(['roomId']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/housekeeping-tasks')->assertNotFound();
    expect(DB::table('housekeeping_tasks')->count())->toBe(0);
});

it('allows member reading but restricts task creation and editing to administrators', function (): void {
    $hotel=Hotel::create(['name'=>'Housekeeping Access']);
    $room=housekeepingRoom($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $payload=['roomId'=>(string)$room->id,'type'=>'stayover'];
    $this->getJson($base)->assertUnauthorized();
    $this->postJson($base,$payload)->assertUnauthorized();
    housekeepingLogin($hotel,'front_desk',['hotel:read','hotel:write']);
    $this->getJson($base)->assertOk();
    $this->postJson($base,$payload)->assertForbidden();
    housekeepingLogin($hotel,'admin',['hotel:read']);
    $this->postJson($base,$payload)->assertForbidden();
});

it('enforces transition sequence, optimistic revisions and atomic audit updates', function (): void {
    $hotel=Hotel::create(['name'=>'Housekeeping Workflow']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $id=$this->postJson($base,['roomId'=>(string)$room->id,'type'=>'inspection'])
        ->assertCreated()->json('data.id');

    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'in_progress','assignedTo'=>'Cleaner A'])
        ->assertOk()->assertJsonPath('data.revision',2)->assertJsonPath('data.status','in_progress');
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'done'])->assertStatus(409);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>2,'status'=>'done','note'=>'Inspector checked'])
        ->assertOk()->assertJsonPath('data.status','done')->assertJsonPath('data.revision',3);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>3,'status'=>'todo'])
        ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->getJson($base.'/'.$id.'/history')->assertOk()->assertJsonCount(3,'data');
    expect(DB::table('housekeeping_tasks')->where('id',$id)->first()->completed_at)->not->toBeNull();
});

it('never makes a physical room ready or changes any reservation from task completion', function (): void {
    $hotel=Hotel::create(['name'=>'Housekeeping Inventory Boundary']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $id=$this->postJson($base,['roomId'=>(string)$room->id,'type'=>'checkout_clean'])
        ->assertCreated()->json('data.id');
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'done'])->assertOk();
    expect(DB::table('reservation_records')->count())->toBe(0);
    expect(DB::table('rooms')->where('id',$room->id)->first()->number)->toBe('101');
    expect(DB::table('housekeeping_task_events')->where('task_id',$id)->count())->toBe(2);
});

it('validates unsupported task types and pagination limits', function (): void {
    $hotel=Hotel::create(['name'=>'Housekeeping Validation']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $this->postJson($base,['roomId'=>(string)$room->id,'type'=>'unapproved_clean'])
        ->assertUnprocessable()->assertJsonValidationErrors(['type']);
    $this->getJson($base.'?per_page=101')->assertUnprocessable();
});

it('records final inspection inside the cleaned housekeeping task without independent approval', function (): void {
    $hotel=Hotel::create(['name'=>'Housekeeping Combined']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $url='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $id=$this->postJson($url,['roomId'=>(string)$room->id,'type'=>'checkout_clean'])
        ->assertCreated()->json('data.id');
    $checks=[
        'expectedRevision'=>1,'cleaningVerified'=>true,
        'safetyVerified'=>true,'amenitiesVerified'=>true,
        'note'=>'Room clean, safe, and fully restocked',
    ];
    $this->postJson($url.'/'.$id.'/final-inspection',$checks)
        ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->patchJson($url.'/'.$id,['expectedRevision'=>1,'status'=>'done'])
        ->assertOk()->assertJsonPath('data.revision',2);
    $checks['expectedRevision']=2;
    $this->postJson($url.'/'.$id.'/final-inspection',$checks)
        ->assertOk()->assertJsonPath('data.revision',3);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id.'/readiness')
        ->assertOk()->assertJsonPath('data.advisoryReady',true)
        ->assertJsonPath('data.inspection.status','approved');
    $this->postJson($url.'/'.$id.'/final-inspection',$checks)->assertStatus(409);
    $this->getJson($url.'/'.$id.'/history')->assertOk()
        ->assertJsonPath('data.0.action','inspection_completed');
    expect(DB::table('room_readiness_inspections')->where('room_id',$room->id)->count())->toBe(1);
});

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

it('returns private personal dashboard counts and prioritizes an assigned arrival room', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T01:00:00+08:00'));
    $hotel=Hotel::create(['name'=>'HK Dashboard','timezone'=>'Asia/Manila']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $cleaner=User::create(['name'=>'Cleaner','email'=>'hk-dashboard@example.test','password'=>'test-password']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$cleaner->id,'role'=>'housekeeping','is_active'=>true]);
    $base='/api/v1/hotels/'.$hotel->id;
    $guest=$hotel->guests()->create(['first_name'=>'Private','last_name'=>'Guest']);
    $booking=$this->postJson($base.'/bookings',[
        'idempotencyKey'=>'hk-dashboard-arrival-01','guestId'=>$guest->id,'roomTypeId'=>$room->room_type_id,
        'checkIn'=>'2026-12-10','checkOut'=>'2026-12-11',
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room',['roomId'=>$room->id])->assertOk();
    $make=fn (string $type, ?int $assignee)=>$this->postJson($base.'/housekeeping-tasks',[
        'roomId'=>$room->id,'type'=>$type,'assignedUserId'=>$assignee,
    ])->assertCreated()->json('data.id');
    $todo=$make('checkout_clean',$cleaner->id);
    $progress=$make('stayover',$cleaner->id);
    $done=$make('stayover',$cleaner->id);
    $make('inspection',null);
    $this->patchJson($base.'/housekeeping-tasks/'.$progress,['expectedRevision'=>1,'status'=>'in_progress'])->assertOk();
    $this->patchJson($base.'/housekeeping-tasks/'.$done,['expectedRevision'=>1,'status'=>'done'])->assertOk();
    $this->getJson($base.'/housekeeping/dashboard')->assertForbidden();
    Sanctum::actingAs($cleaner,['hotel:read']);
    $response=$this->getJson($base.'/housekeeping/dashboard')->assertOk()
        ->assertJsonPath('data.hotelDate','2026-12-10')
        ->assertJsonPath('data.summary.todo',1)->assertJsonPath('data.summary.inProgress',1)
        ->assertJsonPath('data.summary.urgentRooms',1)->assertJsonPath('data.summary.completedToday',1)
        ->assertJsonPath('data.activeTotal',2)->assertJsonCount(2,'data.tasks')
        ->assertJsonPath('data.tasks.0.id',$todo)->assertJsonPath('data.tasks.0.priority','arrival_today')
        ->assertJsonPath('data.tasks.1.priority','in_progress');
    expect($response->getContent())->not->toContain('Private Guest','guestName','bookingId');
    $other=Hotel::create(['name'=>'Other dashboard hotel']);
    $this->getJson('/api/v1/hotels/'.$other->id.'/housekeeping/dashboard')->assertNotFound();
    $this->patchJson($base.'/housekeeping-tasks/'.$todo,['expectedRevision'=>1,'status'=>'in_progress'])
        ->assertOk()->assertJsonPath('data.status','in_progress');
});

it('filters the full housekeeping queue and separates completed cleaning from inspection evidence', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T11:00:00+08:00'));
    $hotel=Hotel::create(['name'=>'HK Queue','timezone'=>'Asia/Manila']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $cleaner=User::create(['name'=>'Queue Cleaner','email'=>'hk-queue@example.test','password'=>'test-password']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$cleaner->id,'role'=>'housekeeping','is_active'=>true]);
    $base='/api/v1/hotels/'.$hotel->id;
    $url=$base.'/housekeeping-tasks';
    $overdue=$this->postJson($url,['roomId'=>$room->id,'type'=>'stayover','assignedUserId'=>$cleaner->id,'dueAt'=>'2026-12-10 10:00:00'])->assertCreated()->json('data.id');
    $pending=$this->postJson($url,['roomId'=>$room->id,'type'=>'checkout_clean','assignedUserId'=>$cleaner->id])->assertCreated()->json('data.id');
    $unassigned=$this->postJson($url,['roomId'=>$room->id,'type'=>'stayover'])->assertCreated()->json('data.id');
    $this->patchJson($url.'/'.$pending,['expectedRevision'=>1,'status'=>'done'])->assertOk();
    $this->getJson($url.'?queue=overdue&mine=0')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.id',$overdue)->assertJsonPath('data.0.overdue',true);
    $this->getJson($url.'?queue=unassigned')->assertOk()->assertJsonPath('data.0.id',$unassigned);
    $this->getJson($url.'?queue=pending_inspection')->assertOk()->assertJsonPath('data.0.id',$pending)->assertJsonPath('data.0.pendingInspection',true);
    $this->getJson($url.'?assignee='.$cleaner->id.'&sort=priority&per_page=1')->assertOk()->assertJsonPath('meta.total',2)->assertJsonPath('data.0.id',$overdue);
    $this->getJson($base.'/housekeeping/workload')->assertOk()->assertJsonPath('data.0.userId',(string)$cleaner->id)->assertJsonPath('data.0.todo',1);
    $this->patchJson($url.'/'.$overdue,['expectedRevision'=>1,'status'=>'done'])->assertOk();
    $this->patchJson($url.'/'.$unassigned,['expectedRevision'=>1,'status'=>'done'])->assertOk();
    Sanctum::actingAs($cleaner,['hotel:read']);
    $this->getJson($base.'/housekeeping/workload')->assertForbidden();
    $this->getJson($url.'?mine=1&queue=pending_inspection')->assertOk()->assertJsonPath('meta.pendingInspectionCount',1);
    $this->postJson($url.'/'.$pending.'/final-inspection',[
        'expectedRevision'=>2,'cleaningVerified'=>true,'safetyVerified'=>true,'amenitiesVerified'=>true,'note'=>'Final inspection complete',
    ])->assertOk();
    $this->getJson($url.'?mine=1&queue=pending_inspection')->assertOk()->assertJsonPath('meta.total',0)->assertJsonPath('meta.pendingInspectionCount',0);
});

it('persists checklist evidence, rejects incomplete assigned cleaning and locks completed evidence', function (): void {
    $hotel=Hotel::create(['name'=>'HK Checklist']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $cleaner=User::create(['name'=>'Checklist Cleaner','email'=>'hk-checklist@example.test','password'=>'test-password']);
    HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$cleaner->id,'role'=>'housekeeping','is_active'=>true]);
    $base='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $task=$this->postJson($base,['roomId'=>$room->id,'type'=>'checkout_clean','assignedUserId'=>$cleaner->id])->assertCreated()
        ->assertJsonCount(8,'data.checklist')->json('data');
    $keys=array_column($task['checklist'],'key');
    $url=$base.'/'.$task['id'];
    Sanctum::actingAs($cleaner,['hotel:read']);
    $this->patchJson($url,['expectedRevision'=>1,'status'=>'done'])->assertUnprocessable()->assertJsonValidationErrors(['checkedItems']);
    $this->patchJson($url,['expectedRevision'=>1,'checkedItems'=>['invalid']])->assertUnprocessable();
    $this->patchJson($url,['expectedRevision'=>1,'status'=>'in_progress','checkedItems'=>[$keys[0]]])->assertOk()
        ->assertJsonPath('data.revision',2)->assertJsonPath('data.checklist.0.checkedBy',(string)$cleaner->id);
    $first=$this->getJson($url)->assertOk()->json('data.checklist.0.checkedAt');
    expect($first)->not->toBeNull();
    $this->patchJson($url,['expectedRevision'=>1,'checkedItems'=>$keys])->assertStatus(409);
    $this->patchJson($url,['expectedRevision'=>2,'status'=>'done','checkedItems'=>[$keys[0]]])->assertUnprocessable();
    $this->patchJson($url,['expectedRevision'=>2,'status'=>'done','checkedItems'=>$keys])->assertOk()
        ->assertJsonPath('data.completedBy',(string)$cleaner->id)->assertJsonPath('data.checklist.0.checkedAt',$first);
    $this->patchJson($url,['expectedRevision'=>3,'checkedItems'=>[]])->assertUnprocessable();
    $this->getJson($url.'/history')->assertOk()->assertJsonPath('data.0.after.completedBy',(string)$cleaner->id);
    expect(DB::table('room_readiness_inspections')->where('hotel_id',$hotel->id)->count())->toBe(0);
});

it('uses a different stayover checklist and does not fabricate legacy manager evidence', function (): void {
    $hotel=Hotel::create(['name'=>'HK Stayover Checklist']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $task=$this->postJson($base,['roomId'=>$room->id,'type'=>'stayover'])->assertCreated()->assertJsonCount(6,'data.checklist')->json('data');
    $url=$base.'/'.$task['id'];
    $this->patchJson($url,['expectedRevision'=>1,'status'=>'done','checkedItems'=>[]])->assertUnprocessable();
    $this->patchJson($url,['expectedRevision'=>1,'status'=>'done'])->assertOk()->assertJsonPath('data.checklist.0.checkedAt',null);
    $this->getJson($url.'/history')->assertOk()->assertJsonPath('data.0.after.checklistEvidence','legacy_status_only');
});

it('does not allow stayover completion to certify a room ready through final inspection', function (): void {
    $hotel=Hotel::create(['name'=>'HK Stayover Boundary']);
    $room=housekeepingRoom($hotel);
    housekeepingLogin($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $task=$this->postJson($base,['roomId'=>$room->id,'type'=>'stayover'])->assertCreated()->json('data');
    $url=$base.'/'.$task['id'];
    $this->patchJson($url,['expectedRevision'=>1,'status'=>'done','checkedItems'=>array_column($task['checklist'],'key')])->assertOk();
    $this->postJson($url.'/final-inspection',['expectedRevision'=>2,'cleaningVerified'=>true,'safetyVerified'=>true,'amenitiesVerified'=>true,'note'=>'Stayover service finished'])->assertUnprocessable();
    expect(DB::table('room_readiness_inspections')->where('hotel_id',$hotel->id)->count())->toBe(0);
});

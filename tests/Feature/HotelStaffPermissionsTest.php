<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function phase15User(string $name): User
{
    return User::create([
        'name'=>$name,
        'email'=>strtolower(str_replace(' ','-',$name)).'-'.uniqid().'@example.test',
        'password'=>'test-only-password',
    ]);
}
function phase15Member(Hotel $hotel, User $user, string $role): void
{
    HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>$role,'is_active'=>true,
    ]);
}
function phase15Room(Hotel $hotel): Room
{
    $type=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    return Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'101']);
}

it('lets administrators attach existing staff without creating a user or exposing credentials', function (): void {
    $hotel=Hotel::create(['name'=>'Staff Hotel']);
    $admin=phase15User('Admin');
    $worker=phase15User('Cleaner');
    phase15Member($hotel,$admin,'admin');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $url='/api/v1/hotels/'.$hotel->id.'/staff';
    $this->postJson($url,['email'=>$worker->email,'role'=>'housekeeping'])
        ->assertCreated()->assertJsonCount(2,'data');
    $this->getJson($url)->assertOk()->assertJsonFragment(['role'=>'housekeeping','name'=>'Cleaner']);
    $this->postJson($url,['email'=>$worker->email,'role'=>'maintenance'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    expect(User::count())->toBe(2);
    $this->getJson($url.'/history')->assertOk()->assertJsonCount(1,'data')
        ->assertJsonPath('data.0.action','attached')
        ->assertJsonPath('data.0.after.role','housekeeping');
});

it('guards staff role updates, admin self lockout, and property isolation', function (): void {
    $a=Hotel::create(['name'=>'Role A']);
    $b=Hotel::create(['name'=>'Role B']);
    $admin=phase15User('Director');
    $worker=phase15User('Engineer');
    phase15Member($a,$admin,'admin');
    phase15Member($a,$worker,'maintenance');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $url='/api/v1/hotels/'.$a->id.'/staff';
    $this->patchJson($url.'/'.$admin->id,['isActive'=>false])
        ->assertUnprocessable();
    $this->patchJson($url.'/'.$worker->id,['role'=>'housekeeping'])
        ->assertOk()->assertJsonFragment(['role'=>'housekeeping','name'=>'Engineer']);
    $this->getJson('/api/v1/hotels/'.$b->id.'/staff')->assertNotFound();
    $this->patchJson('/api/v1/hotels/'.$b->id.'/staff/'.$worker->id,['role'=>'admin'])->assertNotFound();
    expect(HotelMembership::where('hotel_id',$a->id)->where('role','admin')->where('is_active',true)->count())->toBe(1);
    $this->getJson($url.'/history')->assertOk()->assertJsonCount(1,'data')
        ->assertJsonPath('data.0.before.role','maintenance')
        ->assertJsonPath('data.0.after.role','housekeeping');
});

it('does not let non-admins manage staff memberships', function (): void {
    $hotel=Hotel::create(['name'=>'Staff Permissions']);
    $staff=phase15User('Operator');
    phase15Member($hotel,$staff,'housekeeping');
    $url='/api/v1/hotels/'.$hotel->id.'/staff';
    $this->getJson($url)->assertUnauthorized();
    Sanctum::actingAs($staff,['hotel:read']);
    $this->getJson($url)->assertForbidden();
    $this->postJson($url,['email'=>'unknown@example.test','role'=>'admin'])->assertForbidden();
});

it('allows only the linked housekeeping assignee to advance the assigned task', function (): void {
    $hotel=Hotel::create(['name'=>'Assigned Cleaning']);
    $room=phase15Room($hotel);
    $admin=phase15User('Head Admin');
    $cleaner=phase15User('Assigned Cleaner');
    $other=phase15User('Other Cleaner');
    phase15Member($hotel,$admin,'admin');
    phase15Member($hotel,$cleaner,'housekeeping');
    phase15Member($hotel,$other,'housekeeping');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$hotel->id.'/housekeeping-tasks';
    $id=$this->postJson($base,[
        'roomId'=>(string)$room->id,'type'=>'inspection','assignedUserId'=>(string)$cleaner->id,
    ])->assertCreated()->assertJsonPath('data.assignedUserId',(string)$cleaner->id)->json('data.id');

    Sanctum::actingAs($other,['hotel:read']);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'in_progress'])->assertForbidden();
    Sanctum::actingAs($cleaner,['hotel:read']);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'assignedUserId'=>(string)$other->id])
        ->assertForbidden();
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'in_progress','note'=>'Started'])
        ->assertOk()->assertJsonPath('data.revision',2);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'done'])->assertStatus(409);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>2,'status'=>'done'])->assertOk()
        ->assertJsonPath('data.status','done');
    expect(DB::table('housekeeping_task_events')->where('task_id',$id)->count())->toBe(3);
    expect(DB::table('reservation_records')->count())->toBe(0);
});

it('allows only the linked maintenance assignee to advance status, not admin-controlled fields', function (): void {
    $hotel=Hotel::create(['name'=>'Assigned Maintenance']);
    $room=phase15Room($hotel);
    $admin=phase15User('Supervisor');
    $worker=phase15User('Technician');
    phase15Member($hotel,$admin,'admin');
    phase15Member($hotel,$worker,'maintenance');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$hotel->id.'/maintenance-work-orders';
    $id=$this->postJson($base,[
        'roomId'=>(string)$room->id,'title'=>'Air conditioning','description'=>'Compressor inspection',
        'priority'=>'medium','assignedUserId'=>(string)$worker->id,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($worker,['hotel:read']);
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'priority'=>'urgent'])->assertForbidden();
    $this->patchJson($base.'/'.$id,['expectedRevision'=>1,'status'=>'in_progress','note'=>'On site'])
        ->assertOk()->assertJsonPath('data.status','in_progress');
    $this->patchJson($base.'/'.$id,['expectedRevision'=>2,'status'=>'resolved'])
        ->assertOk()->assertJsonPath('data.status','resolved');
    expect(DB::table('maintenance_work_order_events')->where('work_order_id',$id)->count())->toBe(3);
});

it('revokes assigned task writes immediately after deactivating the hotel membership', function (): void {
    $hotel=Hotel::create(['name'=>'Revocation Hotel']);
    $room=phase15Room($hotel);
    $admin=phase15User('Hotel Supervisor');
    $worker=phase15User('Inactive Cleaner');
    phase15Member($hotel,$admin,'admin');
    phase15Member($hotel,$worker,'housekeeping');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$hotel->id;
    $id=$this->postJson($base.'/housekeeping-tasks',[
        'roomId'=>(string)$room->id,'type'=>'stayover','assignedUserId'=>(string)$worker->id,
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/staff/'.$worker->id,['isActive'=>false])->assertOk();

    Sanctum::actingAs($worker,['hotel:read']);
    $this->patchJson($base.'/housekeeping-tasks/'.$id,['expectedRevision'=>1,'status'=>'done'])->assertNotFound();
    expect(DB::table('housekeeping_tasks')->where('id',$id)->first()->status)->toBe('todo');
});

it('rejects assignees who belong only to a different hotel or the wrong operational role', function (): void {
    $a=Hotel::create(['name'=>'Assignment Scope A']);
    $b=Hotel::create(['name'=>'Assignment Scope B']);
    $room=phase15Room($a);
    $admin=phase15User('Assignment Admin');
    $foreign=phase15User('Foreign Worker');
    $wrongRole=phase15User('Front Desk Worker');
    phase15Member($a,$admin,'admin');
    phase15Member($b,$foreign,'housekeeping');
    phase15Member($a,$wrongRole,'front_desk');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$a->id;
    $this->postJson($base.'/housekeeping-tasks',[
        'roomId'=>(string)$room->id,'type'=>'checkout_clean',
        'assignedUserId'=>(string)$foreign->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['assignedUserId']);
    $this->postJson($base.'/housekeeping-tasks',[
        'roomId'=>(string)$room->id,'type'=>'checkout_clean',
        'assignedUserId'=>(string)$wrongRole->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['assignedUserId']);
    $this->postJson($base.'/maintenance-work-orders',[
        'roomId'=>(string)$room->id,'title'=>'Repair','description'=>'Check wiring',
        'priority'=>'medium','assignedUserId'=>(string)$foreign->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['assignedUserId']);
    expect(DB::table('housekeeping_tasks')->count())->toBe(0);
    expect(DB::table('maintenance_work_orders')->count())->toBe(0);
});

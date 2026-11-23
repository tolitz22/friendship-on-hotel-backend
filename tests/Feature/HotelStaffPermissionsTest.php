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
    $this->getJson($url)->assertOk()->assertJsonFragment(['role'=>'housekeeping'])->assertJsonFragment(['name'=>'Cleaner']);
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
        ->assertOk()->assertJsonFragment(['role'=>'housekeeping'])->assertJsonFragment(['name'=>'Engineer']);
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

it('exposes server-enforced role profiles only to hotel administrators', function (): void {
    $hotel=Hotel::create(['name'=>'Role Profile Hotel']);
    $admin=phase15User('Permissions Administrator');
    $reception=phase15User('Permissions Receptionist');
    phase15Member($hotel,$admin,'admin');
    phase15Member($hotel,$reception,'front_desk');
    $url='/api/v1/hotels/'.$hotel->id.'/staff/permissions';

    Sanctum::actingAs($reception,['hotel:read','booking:write']);
    $this->getJson($url)->assertForbidden();

    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $response=$this->getJson($url)->assertOk()
        ->assertJsonPath('data.editable',false)
        ->assertJsonPath('data.roles.0.key','admin')
        ->assertJsonPath('data.roles.0.activeStaff',1);
    $profiles=collect($response->json('data.roles'))->keyBy('key');
    expect($profiles['front_desk']['permissions'])->toContain('booking.create','cashier.write');
    expect($profiles['front_desk']['permissions'])->not->toContain('staff.manage','finance.approve');
    expect($profiles['housekeeping']['permissions'])->toContain('housekeeping.assigned');
    expect($profiles['maintenance']['permissions'])->toContain('maintenance.assigned');
});

it('prevents managers from overwriting another manager’s newer staff access decision', function (): void {
    $hotel=Hotel::create(['name'=>'Staff Conflict Hotel']);
    $admin=phase15User('Concurrency Admin');
    $worker=phase15User('Concurrency Worker');
    phase15Member($hotel,$admin,'admin');
    phase15Member($hotel,$worker,'front_desk');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $url='/api/v1/hotels/'.$hotel->id.'/staff/'.$worker->id;

    $this->patchJson($url,[
        'role'=>'housekeeping','expectedRole'=>'front_desk','expectedIsActive'=>true,
    ])->assertOk();

    $this->patchJson($url,[
        'role'=>'admin','expectedRole'=>'front_desk','expectedIsActive'=>true,
    ])->assertStatus(409);

    expect(HotelMembership::where('hotel_id',$hotel->id)->where('user_id',$worker->id)->first()->role)
        ->toBe('housekeeping');
    expect(DB::table('hotel_staff_events')->where('target_user_id',$worker->id)->count())->toBe(1);
});

it('lets an admin revoke and restore a staff member’s role-scoped permissions', function (): void {
    $hotel=Hotel::create(['name'=>'Editable Staff Grants']);
    $admin=phase15User('Permission Editor Admin');
    $worker=phase15User('Permission Editor Reception');
    phase15Member($hotel,$admin,'admin');
    phase15Member($hotel,$worker,'front_desk');
    $base='/api/v1/hotels/'.$hotel->id;
    $role=\App\Http\Middleware\EnsureHotelPermission::forRole('front_desk');
    $restricted=array_values(array_diff($role,['guest.read','finance.read']));
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $this->patchJson($base.'/staff/'.$worker->id,[
        'permissions'=>$restricted,
        'expectedRole'=>'front_desk','expectedPermissions'=>$role,
    ])->assertOk()->assertJsonPath('data.1.customPermissions',true);
    Sanctum::actingAs($worker,['hotel:read','booking:write']);
    $this->getJson($base.'/guests')->assertForbidden();
    $this->getJson($base.'/finance/folios')->assertForbidden();
    $this->getJson($base.'/reservation-records')->assertOk();
    $this->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonMissing(['permissions'=>$role]);

    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $this->patchJson($base.'/staff/'.$worker->id,[
        'permissions'=>$role,
        'expectedRole'=>'front_desk','expectedPermissions'=>$restricted,
    ])->assertOk()->assertJsonPath('data.1.customPermissions',false);
    Sanctum::actingAs($worker,['hotel:read','booking:write']);
    $this->getJson($base.'/guests')->assertOk();
});

it('rejects privilege escalation, manager customization and stale permission edits', function (): void {
    $hotel=Hotel::create(['name'=>'Locked Staff Privileges']);
    $admin=phase15User('Privilege Admin');
    $worker=phase15User('Privilege Housekeeper');
    phase15Member($hotel,$admin,'admin');
    phase15Member($hotel,$worker,'housekeeping');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$hotel->id.'/staff/';
    $this->patchJson($base.$worker->id,[
        'permissions'=>['booking.create'],'expectedRole'=>'housekeeping',
    ])->assertUnprocessable()->assertJsonValidationErrors(['permissions']);
    $this->patchJson($base.$admin->id,[
        'permissions'=>[],
    ])->assertUnprocessable()->assertJsonValidationErrors(['permissions']);
    $this->patchJson($base.$worker->id,[
        'permissions'=>[],'expectedRole'=>'housekeeping',
        'expectedPermissions'=>['occupancy.read','occupancy.observe'],
    ])->assertOk();
    $this->patchJson($base.$worker->id,[
        'permissions'=>['occupancy.read'],
        'expectedPermissions'=>['occupancy.read','occupancy.observe'],
    ])->assertStatus(409);
    expect(\App\Models\HotelMembership::where('hotel_id',$hotel->id)
        ->where('user_id',$worker->id)->first()->permission_overrides)->toBe([]);
    expect(DB::table('hotel_staff_events')->where('target_user_id',$worker->id)->count())->toBe(1);
});

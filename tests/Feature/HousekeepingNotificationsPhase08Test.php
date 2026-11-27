<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelNotificationService;
use App\Services\PushAlertPayload;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('delivers private assignment alerts only to the current housekeeper and cancels stale manager alerts', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T10:00:00+08:00'));
    $hotel=Hotel::create(['name'=>'HK notifications','timezone'=>'Asia/Manila']);
    $type=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $room=Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'201']);
    $staff=[];
    foreach (['admin','housekeeping','housekeeping'] as $role) {
        $user=User::create(['name'=>'HK '.$role,'email'=>Str::uuid().'@example.test','password'=>'Password123456!']);
        HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>$role,'is_active'=>true]);
        $staff[]=$user;
    }
    [$admin,$first,$second]=$staff;
    $base='/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $task=$this->postJson($base.'/housekeeping-tasks',['roomId'=>$room->id,'type'=>'stayover',
        'assignedUserId'=>$first->id,'dueAt'=>'2026-12-10 09:00:00'])->assertCreated()->json('data');
    $url=$base.'/housekeeping/notifications';
    Sanctum::actingAs($first,['hotel:read']);
    $feed=$this->getJson($url)->assertOk()->json('data.notifications');
    expect(array_column($feed,'kind'))->toContain('hk_assigned','hk_overdue');
    Sanctum::actingAs($second,['hotel:read']);
    $this->getJson($url)->assertOk()->assertJsonCount(0,'data.notifications');
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $this->patchJson($base.'/housekeeping-tasks/'.$task['id'],['expectedRevision'=>1,'assignedUserId'=>$second->id])->assertOk();
    Sanctum::actingAs($first,['hotel:read']);
    $this->getJson($url)->assertOk()->assertJsonCount(0,'data.notifications');
    Sanctum::actingAs($second,['hotel:read']);
    $feed=$this->getJson($url)->assertOk()->json('data.notifications');
    expect(array_column($feed,'kind'))->toContain('hk_assigned','hk_overdue');
    $found=$this->postJson($base.'/lost-found',['roomId'=>$room->id,'description'=>'Umbrella','storageLocation'=>'Locker A','requestKey'=>(string)Str::uuid()])->assertCreated()->json('data.id');
    $service=app(HotelNotificationService::class);
    $lost=$service->current($hotel,'lost_found_registered:'.$found);
    expect($lost['itemId'])->toBe($found);
    expect(PushAlertPayload::make($hotel->id,$lost)['url'])->toBe('/lost-found?hotel='.$hotel->id.'&item='.$found);
    $scope=app(\App\Services\PushRecipientScope::class);
    $assignment=collect($feed)->firstWhere('kind','hk_assigned');
    expect($scope->role($hotel,$second->id))->toBeNull();
    $this->getJson($base.'/push-access')->assertOk()->assertJsonPath('data.allowed',false);
    config(['push.staff_enabled'=>true]);
    $this->getJson($base.'/push-access')->assertOk()->assertJsonPath('data.allowed',true);
    $this->getJson($base.'/push-preferences')->assertOk()->assertJsonPath('data.hk_assigned',true);
    expect($scope->current($hotel,$second->id,'hk_assigned',$assignment['id']))->not->toBeNull();
    expect($scope->current($hotel,$first->id,'hk_assigned',$assignment['id']))->toBeNull();
    expect($scope->current($hotel,$second->id,'lost_found_registered','lost_found_registered:'.$found))->toBeNull();
    $membership=HotelMembership::where('hotel_id',$hotel->id)->where('user_id',$second->id)->firstOrFail();
    $membership->update(['permission_overrides'=>[]]);
    expect($scope->current($hotel,$second->id,'hk_assigned',$assignment['id']))->toBeNull();
    $this->getJson($base.'/push-preferences')->assertForbidden();
    $membership->update(['permission_overrides'=>null]);
    $overdue='hk_overdue:'.$task['id'].':2026-12-10';
    expect($service->current($hotel,$overdue))->not->toBeNull();
    $this->patchJson($base.'/housekeeping-tasks/'.$task['id'],['expectedRevision'=>2,'status'=>'done','checkedItems'=>array_column($task['checklist'],'key')])->assertOk();
    expect($service->current($hotel,$overdue))->toBeNull();
    $this->getJson($url)->assertOk()->assertJsonCount(0,'data.notifications');
    $this->getJson($base.'/front-desk/notifications')->assertForbidden();
});

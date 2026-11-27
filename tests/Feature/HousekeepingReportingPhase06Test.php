<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function hkSixFixture(): array
{
    $hotel=Hotel::create(['name'=>'HK report hotel']);
    $type=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $room=Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'101']);
    $staff=[];
    foreach (['admin','housekeeping','housekeeping','maintenance'] as $role) {
        $user=User::create(['name'=>'HK '.$role,'email'=>Str::uuid().'@example.test','password'=>'Password123456!']);
        HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>$role,'is_active'=>true]);
        $staff[]=$user;
    }
    return [$hotel,$room,...$staff];
}

it('lets assigned housekeeping report maintenance once without granting work order management', function (): void {
    [$hotel,$room,$admin,$cleaner,$other]=hkSixFixture();
    $base='/api/v1/hotels/'.$hotel->id;
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $task=$this->postJson($base.'/housekeeping-tasks',['roomId'=>$room->id,'type'=>'checkout_clean','assignedUserId'=>$cleaner->id])->assertCreated()->json('data.id');
    $report=['title'=>'Leaking tap','description'=>'Water leaking onto floor','priority'=>'urgent','affectsRoom'=>true,'requestKey'=>(string)Str::uuid()];
    $url=$base.'/housekeeping-tasks/'.$task.'/maintenance-report';
    Sanctum::actingAs($other,['hotel:read']);
    $this->postJson($url,$report)->assertForbidden();
    Sanctum::actingAs($cleaner,['hotel:read']);
    $order=$this->postJson($url,$report)->assertCreated()->assertJsonPath('data.housekeepingTaskId',$task)->assertJsonPath('data.affectsRoom',true)->json('data.id');
    $this->postJson($url,$report)->assertOk()->assertJsonPath('data.id',$order);
    $this->postJson($url,array_merge($report,['title'=>'Other issue']))->assertConflict();
    expect(DB::table('maintenance_work_orders')->where('hotel_id',$hotel->id)->count())->toBe(1);
    $this->patchJson($base.'/maintenance-work-orders/'.$order,['expectedRevision'=>1,'status'=>'resolved'])->assertForbidden();
    $this->getJson($base.'/housekeeping/room-board')->assertOk()->assertJsonPath('data.rooms.0.stage','blocked');
    $foreign=Hotel::create(['name'=>'Foreign reports']);
    $this->postJson('/api/v1/hotels/'.$foreign->id.'/housekeeping-tasks/'.$task.'/maintenance-report',$report)->assertNotFound();
});

it('registers a found item and protects its custody trail and final outcome', function (): void {
    [$hotel,$room,$admin,$cleaner,$other,$maintenance]=hkSixFixture();
    $url='/api/v1/hotels/'.$hotel->id.'/lost-found';
    $payload=['roomId'=>$room->id,'description'=>'Black umbrella','storageLocation'=>'Locker A','requestKey'=>(string)Str::uuid()];
    Sanctum::actingAs($cleaner,['hotel:read']);
    $item=$this->postJson($url,$payload)->assertCreated()->assertJsonPath('data.status','stored')->json('data.id');
    $this->postJson($url,$payload)->assertOk()->assertJsonPath('data.id',$item);
    $this->getJson($url)->assertOk()->assertJsonCount(1,'data');
    $this->patchJson($url.'/'.$item,['expectedRevision'=>1,'action'=>'return','note'=>'Verified handover'])->assertForbidden();
    Sanctum::actingAs($other,['hotel:read']);
    $this->getJson($url)->assertOk()->assertJsonCount(0,'data');
    $this->getJson($url.'/'.$item.'/history')->assertNotFound();
    Sanctum::actingAs($maintenance,['hotel:read']);
    $this->getJson($url)->assertForbidden();
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $this->patchJson($url.'/'.$item,['expectedRevision'=>1,'action'=>'move','storageLocation'=>'Locker B','note'=>'Transferred to supervisor'])->assertOk()->assertJsonPath('data.revision',2);
    $this->patchJson($url.'/'.$item,['expectedRevision'=>1,'action'=>'return','note'=>'Verified handover'])->assertConflict();
    $this->patchJson($url.'/'.$item,['expectedRevision'=>2,'action'=>'return','note'=>'Verified claimant and handed over item'])->assertOk()->assertJsonPath('data.status','returned');
    $this->patchJson($url.'/'.$item,['expectedRevision'=>3,'action'=>'dispose','note'=>'Policy authorization'])->assertConflict();
    $this->getJson($url.'/'.$item.'/history')->assertOk()->assertJsonCount(3,'data')->assertJsonPath('data.0.action','return');
    Sanctum::actingAs($cleaner,['hotel:read']);
    $this->getJson($url.'/'.$item.'/history')->assertOk()->assertJsonPath('data.0.note',null);
});

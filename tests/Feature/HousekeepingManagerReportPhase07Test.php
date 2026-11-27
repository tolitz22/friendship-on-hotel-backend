<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('reports recorded cleaning and checkout turnover by local date and completion actor', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-12-10T08:00:00+08:00'));
    $hotel=Hotel::create(['name'=>'HK report','timezone'=>'Asia/Manila']);
    $type=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $room=Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'201']);
    $otherRoom=Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'202']);
    $admin=User::create(['name'=>'Manager','email'=>Str::uuid().'@example.test','password'=>'Password123456!']);
    $cleaner=User::create(['name'=>'Cleaner','email'=>Str::uuid().'@example.test','password'=>'Password123456!']);
    foreach ([[$admin,'admin'],[$cleaner,'housekeeping']] as [$user,$role]) {
        HotelMembership::create(['hotel_id'=>$hotel->id,'user_id'=>$user->id,'role'=>$role,'is_active'=>true]);
    }
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $base='/api/v1/hotels/'.$hotel->id;
    $guest=$hotel->guests()->create(['first_name'=>'Test','last_name'=>'Guest']);
    $booking=$this->postJson($base.'/bookings',[
        'idempotencyKey'=>'hk07-'.Str::uuid(),'guestId'=>$guest->id,'roomTypeId'=>$type->id,
        'checkIn'=>'2026-12-10','checkOut'=>'2026-12-11',
    ])->assertCreated()->json('data.id');
    $this->patchJson($base.'/bookings/'.$booking.'/assign-room',['roomId'=>$room->id])->assertOk();
    $this->postJson($base.'/bookings/'.$booking.'/check-in')->assertOk();
    $this->travelTo(new DateTimeImmutable('2026-12-11T08:00:00+08:00'));
    $this->postJson($base.'/bookings/'.$booking.'/check-out')->assertOk();
    $task=$this->getJson($base.'/housekeeping-tasks?type=checkout_clean')->assertOk()->json('data.0');
    $url=$base.'/housekeeping-tasks/'.$task['id'];
    $this->patchJson($url,['expectedRevision'=>$task['revision'],'assignedUserId'=>$cleaner->id])->assertOk();
    $this->postJson($base.'/housekeeping-tasks',['roomId'=>$otherRoom->id,'type'=>'stayover','dueAt'=>'2026-12-11 08:00:00'])->assertCreated();
    Sanctum::actingAs($cleaner,['hotel:read']);
    $this->travelTo(new DateTimeImmutable('2026-12-11T08:10:00+08:00'));
    $this->patchJson($url,['expectedRevision'=>$task['revision']+1,'status'=>'in_progress'])->assertOk();
    $this->travelTo(new DateTimeImmutable('2026-12-11T08:40:00+08:00'));
    $this->patchJson($url,['expectedRevision'=>$task['revision']+2,'status'=>'done','checkedItems'=>array_column($task['checklist'],'key')])->assertOk();
    $this->getJson($base.'/housekeeping/manager-report')->assertForbidden();
    $this->travelTo(new DateTimeImmutable('2026-12-11T08:50:00+08:00'));
    $this->postJson($url.'/final-inspection',['expectedRevision'=>$task['revision']+3,'cleaningVerified'=>true,'safetyVerified'=>true,'amenitiesVerified'=>true,'note'=>'All physical checks complete'])->assertOk();
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    $report=$this->getJson($base.'/housekeeping/manager-report?days=1')->assertOk()
        ->assertJsonPath('data.hotelDate','2026-12-11')->assertJsonPath('data.summary.checkoutCompleted',1)
        ->assertJsonPath('data.summary.stayoverCompleted',0)->assertJsonPath('data.summary.cleaningSamples',1)
        ->assertJsonPath('data.summary.averageCleaningMinutes',30)
        ->assertJsonPath('data.summary.turnoverSamples',1)->assertJsonPath('data.summary.averageTurnoverMinutes',50)
        ->assertJsonPath('data.summary.inspectionsCompleted',1)->assertJsonPath('data.summary.unassigned',1)
        ->assertJsonPath('data.summary.overdue',1)->assertJsonPath('data.summary.pendingInspection',0);
    $staff=collect($report->json('data.staff'))->firstWhere('userId',(string)$cleaner->id);
    expect($staff['checkoutCompleted'])->toBe(1);
    $this->getJson($base.'/housekeeping/manager-report?days=7')->assertOk()->assertJsonCount(7,'data.daily');
    $this->getJson($base.'/housekeeping/manager-report?days=15')->assertUnprocessable();
    $foreign=Hotel::create(['name'=>'Foreign report']);
    $this->getJson('/api/v1/hotels/'.$foreign->id.'/housekeeping/manager-report')->assertNotFound();
});

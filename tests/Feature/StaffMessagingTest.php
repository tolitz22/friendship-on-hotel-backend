<?php

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['staff_messaging.realtime.enabled' => false]);
    $this->hotel = Hotel::create(['name'=>'Messaging QA','timezone'=>'Asia/Manila','currency'=>'PHP']);
    $this->otherHotel = Hotel::create(['name'=>'Other QA','timezone'=>'Asia/Manila','currency'=>'PHP']);
    $staff = function (Hotel $hotel, string $role): User {
        $user = User::create(['name'=>$role,'email'=>Str::uuid().'@example.test','password'=>'test-password','is_active'=>true,'must_change_password'=>false]);
        $hotel->memberships()->create(['user_id'=>$user->id,'role'=>$role,'is_active'=>true]);
        return $user;
    };
    $this->manager = $staff($this->hotel,'admin');
    $this->reception = $staff($this->hotel,'front_desk');
    $this->cleaner = $staff($this->hotel,'housekeeping');
    $this->outsider = $staff($this->otherHotel,'admin');
    $this->base = '/api/v1/hotels/'.$this->hotel->id.'/staff-messaging';
    Sanctum::actingAs($this->manager,['hotel:read','hotel:write']);
});

it('isolates directory and conversation creation by hotel', function () {
    $ids = collect($this->getJson($this->base.'/directory')->assertOk()->json('data'))->pluck('id')->map(fn ($id)=>(int)$id);
    expect($ids->contains($this->reception->id))->toBeTrue();
    expect($ids->contains($this->outsider->id))->toBeFalse();
    $this->postJson($this->base.'/conversations',['member_ids'=>[$this->outsider->id]])->assertStatus(422);
    Sanctum::actingAs($this->outsider,['hotel:read','hotel:write']);
    $this->getJson($this->base.'/conversations')->assertNotFound();
});

it('reuses direct conversations and prevents duplicate messages and unauthorized access', function () {
    $payload=['member_ids'=>[$this->reception->id]];
    $id=$this->postJson($this->base.'/conversations',$payload)->assertOk()->json('data.id');
    $this->postJson($this->base.'/conversations',$payload)->assertOk()->assertJsonPath('data.id',$id);
    $message=['body'=>'QA message','client_id'=>(string)Str::uuid()];
    $url=$this->base.'/conversations/'.$id;
    $mid=$this->postJson($url.'/messages',$message)->assertOk()->json('data.id');
    $this->postJson($url.'/messages',$message)->assertOk()->assertJsonPath('data.id',$mid);
    $this->postJson($url.'/messages',array_replace($message,['body'=>'Changed']))->assertStatus(409);
    expect(DB::table('staff_messages')->where('conversation_id',$id)->count())->toBe(1);
    Sanctum::actingAs($this->cleaner,['hotel:read']);
    $this->getJson($url)->assertNotFound();
    $this->postJson($url.'/messages',['body'=>'Unauthorized','client_id'=>(string)Str::uuid()])->assertNotFound();
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.body','QA message');
    $this->postJson($url.'/read',['message_id'=>$mid])->assertOk();
    expect((int)DB::table('staff_conversation_members')->where('conversation_id',$id)->where('user_id',$this->reception->id)->value('last_read_message_id'))->toBe((int)$mid);
});

it('revokes department access immediately after a role change', function () {
    Sanctum::actingAs($this->cleaner,['hotel:read']);
    $channels=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'));
    $id=$channels->firstWhere('title','Housekeeping')['id'];
    expect($channels->pluck('title')->contains('Maintenance'))->toBeFalse();
    $this->getJson($this->base.'/conversations/'.$id)->assertOk();
    $this->cleaner->memberships()->where('hotel_id',$this->hotel->id)->update(['role'=>'maintenance']);
    $this->getJson($this->base.'/conversations/'.$id)->assertNotFound();
    $channels=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'));
    expect($channels->pluck('title')->contains('Housekeeping'))->toBeFalse();
    expect($channels->pluck('title')->contains('Maintenance'))->toBeTrue();
});

it('restricts realtime subscriptions to the authenticated user and hotel', function () {
    config(['staff_messaging.realtime.enabled'=>true,'staff_messaging.realtime.app_id'=>'test-app','staff_messaging.realtime.key'=>'test-key','staff_messaging.realtime.secret'=>'test-secret']);
    $channel='private-staff.hotel.'.$this->hotel->id.'.user.'.$this->manager->id;
    $socket='123.456';
    $this->postJson($this->base.'/realtime/auth',['socket_id'=>$socket,'channel_name'=>$channel])->assertOk()
        ->assertJsonPath('auth','test-key:'.hash_hmac('sha256',$socket.':'.$channel,'test-secret'));
    $this->postJson($this->base.'/realtime/auth',['socket_id'=>$socket,'channel_name'=>'private-staff.hotel.'.$this->hotel->id.'.user.'.$this->cleaner->id])->assertForbidden();
    $this->postJson($this->base.'/realtime/auth',['socket_id'=>'invalid','channel_name'=>$channel])->assertStatus(422);
});

it('validates attachment content, size and required fields before persistence', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $url=$this->base.'/conversations/'.$id.'/messages';
    foreach ([
        ['filename'=>'fake.png','content'=>base64_encode('<html>Not an image</html>')],
        ['filename'=>'broken.pdf','content'=>'!!!invalid!!!'],
        ['filename'=>'empty.pdf','content'=>''],
        ['filename'=>'large.pdf','content'=>base64_encode('%PDF-1.4'.str_repeat('x',2097152))],
        ['filename'=>'missing.pdf'],
        ['content'=>base64_encode('%PDF-1.4')],
    ] as $attachment) {
        $this->postJson($url,['client_id'=>(string)Str::uuid(),'attachment'=>$attachment])->assertStatus(422);
    }
    expect(DB::table('staff_messages')->where('conversation_id',$id)->count())->toBe(0);
    expect(DB::table('staff_message_attachments')->count())->toBe(0);
});

it('stores one private attachment on retry and sanitizes its filename', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $url=$this->base.'/conversations/'.$id;
    $bytes="%PDF-1.4\n% Synthetic QA document\n%%EOF\n";
    $payload=['client_id'=>(string)Str::uuid(),'attachment'=>['filename'=>'../../room"report.exe','content'=>base64_encode($bytes)]];
    $mid=$this->postJson($url.'/messages',$payload)->assertOk()->json('data.id');
    $this->postJson($url.'/messages',$payload)->assertOk()->assertJsonPath('data.id',$mid);
    $changed=$payload;
    $changed['attachment']['content']=base64_encode($bytes.'changed');
    $this->postJson($url.'/messages',$changed)->assertStatus(409);
    expect(DB::table('staff_message_attachments')->where('message_id',$mid)->count())->toBe(1);
    $meta=$this->getJson($url)->assertOk()->json('data.0.attachment');
    expect($meta['filename'])->toBe('room_report.pdf');
    expect($meta['mime'])->toBe('application/pdf');
    expect((int)$meta['size'])->toBe(strlen($bytes));
    expect(array_key_exists('encoded_content',$meta))->toBeFalse();
    expect(array_key_exists('sha256',$meta))->toBeFalse();
    Sanctum::actingAs($this->reception,['hotel:read']);
    $download=$this->get($url.'/attachments/'.$meta['id'])->assertOk()
        ->assertHeader('Content-Type','application/octet-stream')
        ->assertHeader('X-Content-Type-Options','nosniff');
    expect($download->getContent())->toBe($bytes);
    expect($download->headers->get('Content-Disposition'))->toContain('attachment; filename="room_report.pdf"');
    expect($download->headers->get('Cache-Control'))->toContain('no-store');
});

it('blocks attachment downloads outside the authorized conversation and hotel', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $url=$this->base.'/conversations/'.$id;
    $mid=$this->postJson($url.'/messages',['client_id'=>(string)Str::uuid(),'attachment'=>['filename'=>'test.pdf','content'=>base64_encode("%PDF-1.4\n%%EOF\n")]])->assertOk()->json('data.id');
    $aid=DB::table('staff_message_attachments')->where('message_id',$mid)->value('id');
    $other=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->cleaner->id]])->assertOk()->json('data.id');
    $this->getJson($this->base.'/conversations/'.$other.'/attachments/'.$aid)->assertNotFound();
    Sanctum::actingAs($this->cleaner,['hotel:read']);
    $this->getJson($url.'/attachments/'.$aid)->assertNotFound();
    Sanctum::actingAs($this->outsider,['hotel:read','hotel:write']);
    $this->getJson($url.'/attachments/'.$aid)->assertNotFound();
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->reception->memberships()->where('hotel_id',$this->hotel->id)->update(['is_active'=>false]);
    $this->getJson($url.'/attachments/'.$aid)->assertNotFound();
});

it('searches and shares only rooms in the active hotel and redacts deleted references', function () {
    $type=$this->hotel->roomTypes()->create(['name'=>'QA Standard','max_occupancy'=>2]);
    $room=$this->hotel->rooms()->create(['room_type_id'=>$type->id,'number'=>'QA203','floor_number'=>2]);
    $foreignType=$this->otherHotel->roomTypes()->create(['name'=>'QA Standard','max_occupancy'=>2]);
    $foreign=$this->otherHotel->rooms()->create(['room_type_id'=>$foreignType->id,'number'=>'QA999','floor_number'=>2]);
    $this->getJson($this->base.'/records?type=room&q=QA203')->assertOk()
        ->assertJsonCount(1,'data')->assertJsonPath('data.0.id',(string)$room->id);
    $this->getJson($this->base.'/records?type=room&q=QA999')->assertOk()->assertJsonCount(0,'data');
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $url=$this->base.'/conversations/'.$id;
    $payload=['client_id'=>(string)Str::uuid(),'record_type'=>'room','record_id'=>$room->id];
    $this->postJson($url.'/messages',array_replace($payload,['record_id'=>$foreign->id]))->assertStatus(422);
    $this->postJson($url.'/messages',array_replace($payload,['record_type'=>'guest']))->assertStatus(422);
    $this->postJson($url.'/messages',array_diff_key($payload,['record_id'=>true]))->assertStatus(422);
    $mid=$this->postJson($url.'/messages',$payload)->assertOk()->assertJsonPath('data.record.label','Room QA203')->json('data.id');
    $this->postJson($url.'/messages',$payload)->assertOk()->assertJsonPath('data.id',$mid);
    $this->postJson($url.'/messages',array_replace($payload,['body'=>'Changed']))->assertStatus(409);
    expect(DB::table('staff_messages')->where('conversation_id',$id)->count())->toBe(1);
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.record.id',(string)$room->id);
    $this->reception->memberships()->where('hotel_id',$this->hotel->id)->update(['permission_overrides'=>json_encode([])]);
    $this->getJson($this->base.'/records?type=room')->assertForbidden();
    $result=$this->getJson($url)->assertOk()->assertJsonPath('data.0.record',null)->assertJsonPath('data.0.record_unavailable',true)->json('data.0');
    expect(array_key_exists('record_id',$result))->toBeFalse();
    expect(array_key_exists('record_type',$result))->toBeFalse();
    $inbox=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'))->firstWhere('id',$id);
    expect($inbox['latest']['record'])->toBeNull();
    expect(array_key_exists('record_id',$inbox['latest']))->toBeFalse();
    Sanctum::actingAs($this->manager,['hotel:read','hotel:write']);
    $room->delete();
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.record_unavailable',true)->assertJsonPath('data.0.record',null);
});

it('enforces the record picker role matrix and rejects unauthorized record types', function () {
    Sanctum::actingAs($this->cleaner,['hotel:read']);
    $this->getJson($this->base.'/records?type=booking')->assertForbidden();
    $this->getJson($this->base.'/records?type=housekeeping')->assertOk();
    $this->getJson($this->base.'/records?type=maintenance')->assertOk();
    $this->getJson($this->base.'/records?type=guest')->assertStatus(422);
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->manager->id]])->assertOk()->json('data.id');
    $this->postJson($this->base.'/conversations/'.$id.'/messages',['client_id'=>(string)Str::uuid(),'record_type'=>'booking','record_id'=>1])->assertStatus(422);
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($this->base.'/records?type=booking')->assertOk();
    $this->getJson($this->base.'/records?type=housekeeping')->assertForbidden();
    $this->getJson($this->base.'/records?type=maintenance')->assertForbidden();
});

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

it('restricts announcement publishing to managers with write capability and hotel channels', function () {
    $channels=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'));
    $id=$channels->firstWhere('title','All Staff')['id'];
    $direct=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $payload=['client_id'=>(string)Str::uuid(),'body'=>'QA announcement','is_announcement'=>true];
    $this->postJson($this->base.'/conversations/'.$direct.'/messages',$payload)->assertStatus(422);
    Sanctum::actingAs($this->manager,['hotel:read']);
    $this->postJson($this->base.'/conversations/'.$id.'/messages',$payload)->assertForbidden();
    Sanctum::actingAs($this->reception,['hotel:read','hotel:write']);
    $this->getJson($this->base.'/conversations')->assertOk();
    $this->postJson($this->base.'/conversations/'.$id.'/messages',$payload)->assertForbidden();
    Sanctum::actingAs($this->manager,['hotel:read','hotel:write']);
    $this->postJson($this->base.'/conversations/'.$id.'/messages',array_replace($payload,['body'=>'   ']))->assertStatus(422);
    expect(DB::table('staff_messages')->count())->toBe(0);
});

it('captures announcement recipients once and tracks explicit acknowledgements idempotently', function () {
    $channels=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'));
    $id=$channels->firstWhere('title','All Staff')['id'];
    $url=$this->base.'/conversations/'.$id;
    $payload=['client_id'=>(string)Str::uuid(),'body'=>'QA announcement','is_announcement'=>true];
    $mid=$this->postJson($url.'/messages',$payload)->assertOk()
        ->assertJsonPath('data.is_announcement',true)
        ->assertJsonPath('data.acknowledgement.total',2)
        ->assertJsonPath('data.acknowledgement.acknowledged',0)->json('data.id');
    $this->postJson($url.'/messages',$payload)->assertOk()->assertJsonPath('data.id',$mid);
    $recipients=DB::table('staff_announcement_recipients')->where('message_id',$mid);
    expect($recipients->count())->toBe(2);
    $ack=$url.'/announcements/'.$mid.'/acknowledge';
    $this->postJson($ack)->assertNotFound();
    Sanctum::actingAs($this->outsider,['hotel:read','hotel:write']);
    $this->postJson($ack)->assertNotFound();
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($this->base.'/conversations')->assertOk();
    $this->postJson($url.'/read',['message_id'=>$mid])->assertOk();
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.acknowledgement.required',true)->assertJsonPath('data.0.acknowledgement.acknowledged_at',null);
    $this->postJson($ack)->assertOk();
    $first=(clone $recipients)->where('user_id',$this->reception->id)->value('acknowledged_at');
    expect($first)->not->toBeNull();
    $this->travel(1)->minutes();
    $this->postJson($ack)->assertOk();
    expect((clone $recipients)->where('user_id',$this->reception->id)->value('acknowledged_at'))->toBe($first);
    $this->travelBack();
    Sanctum::actingAs($this->manager,['hotel:read','hotel:write']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.acknowledgement.total',2)->assertJsonPath('data.0.acknowledgement.acknowledged',1);
});

it('limits department announcements to the intended recipients', function () {
    $channels=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'));
    $id=$channels->firstWhere('title','Housekeeping')['id'];
    $url=$this->base.'/conversations/'.$id;
    $mid=$this->postJson($url.'/messages',['client_id'=>(string)Str::uuid(),'body'=>'QA housekeeping announcement','is_announcement'=>true])->assertOk()
        ->assertJsonPath('data.acknowledgement.total',1)->json('data.id');
    expect(DB::table('staff_announcement_recipients')->where('message_id',$mid)->pluck('user_id')->map(fn ($id)=>(int)$id)->all())->toBe([(int)$this->cleaner->id]);
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($this->base.'/conversations')->assertOk();
    $this->postJson($url.'/announcements/'.$mid.'/acknowledge')->assertNotFound();
    Sanctum::actingAs($this->cleaner,['hotel:read']);
    $this->getJson($this->base.'/conversations')->assertOk();
    $this->postJson($url.'/announcements/'.$mid.'/acknowledge')->assertOk();
    $this->cleaner->memberships()->where('hotel_id',$this->hotel->id)->update(['role'=>'maintenance']);
    $this->postJson($url.'/announcements/'.$mid.'/acknowledge')->assertNotFound();
});

it('validates structured handovers and preserves recipient acknowledgement counts on retries', function () {
    Sanctum::actingAs($this->reception,['hotel:read']);
    $channels=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'));
    $id=$channels->firstWhere('title','Reception')['id'];
    $url=$this->base.'/conversations/'.$id;
    $direct=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->manager->id]])->assertOk()->json('data.id');
    $payload=['client_id'=>(string)Str::uuid(),'handover'=>['incoming_shift'=>'Morning','outstanding'=>'Inspect room QA203','reminders'=>'Check arrivals']];
    $this->postJson($this->base.'/conversations/'.$direct.'/messages',$payload)->assertStatus(422);
    $this->postJson($url.'/messages',array_replace($payload,['handover'=>['incoming_shift'=>'Morning','outstanding'=>' ']]))->assertStatus(422);
    $this->postJson($url.'/messages',array_replace($payload,['is_announcement'=>true]))->assertStatus(422);
    $mid=$this->postJson($url.'/messages',$payload)->assertOk()
        ->assertJsonPath('data.handover.incoming_shift','Morning')
        ->assertJsonPath('data.is_announcement',false)
        ->assertJsonPath('data.acknowledgement.total',1)->json('data.id');
    $this->postJson($url.'/messages',$payload)->assertOk()->assertJsonPath('data.id',$mid);
    $changed=$payload;
    $changed['handover']['outstanding']='Changed task';
    $this->postJson($url.'/messages',$changed)->assertStatus(409);
    expect(DB::table('staff_messages')->where('conversation_id',$id)->count())->toBe(1);
    expect(DB::table('staff_announcement_recipients')->where('message_id',$mid)->count())->toBe(1);
    Sanctum::actingAs($this->manager,['hotel:read','hotel:write']);
    $this->getJson($this->base.'/conversations')->assertOk();
    $this->getJson($url.'?q=QA203')->assertOk()->assertJsonCount(1,'data');
    $this->postJson($url.'/announcements/'.$mid.'/acknowledge')->assertOk();
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.acknowledgement.acknowledged',1);
});

it('rejects malformed structured message payloads even with valid text', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $url=$this->base.'/conversations/'.$id.'/messages';
    foreach ([['attachment'=>[]],['handover'=>[]],['attachment'=>['filename'=>'test.pdf','content'=>base64_encode('%PDF-1.4'),'unexpected'=>true]]] as $invalid) {
        $this->postJson($url,array_merge(['body'=>'QA text','client_id'=>(string)Str::uuid()],$invalid))->assertStatus(422);
    }
    expect(DB::table('staff_messages')->where('conversation_id',$id)->count())->toBe(0);
});

it('rejects corrupted attachments rather than returning damaged bytes', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $url=$this->base.'/conversations/'.$id;
    $mid=$this->postJson($url.'/messages',['client_id'=>(string)Str::uuid(),'attachment'=>['filename'=>'test.pdf','content'=>base64_encode("%PDF-1.4\n%%EOF\n")]])->assertOk()->json('data.id');
    $attachment=DB::table('staff_message_attachments')->where('message_id',$mid);
    $aid=$attachment->value('id');
    $attachment->update(['sha256'=>str_repeat('0',64)]);
    $this->getJson($url.'/attachments/'.$aid)->assertStatus(409);
});

it('treats search wildcards as literal text and matches case independently', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $url=$this->base.'/conversations/'.$id;
    foreach (['QA 100% complete','QA other message'] as $body) {
        $this->postJson($url.'/messages',['body'=>$body,'client_id'=>(string)Str::uuid()])->assertOk();
    }
    $this->getJson($url.'?q='.rawurlencode('100%'))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.body','QA 100% complete');
    $this->getJson($url.'?q=qa')->assertOk()->assertJsonCount(2,'data');
    $this->getJson($url.'?q=%25')->assertOk()->assertJsonCount(1,'data');
});

it('excludes inactive staff from the participant list and denies their conversation access', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $this->reception->memberships()->where('hotel_id',$this->hotel->id)->update(['is_active'=>false]);
    $row=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'))->firstWhere('id',$id);
    expect(collect($row['members'])->pluck('id')->map(fn ($id)=>(int)$id)->contains((int)$this->reception->id))->toBeFalse();
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($this->base.'/conversations/'.$id)->assertNotFound();
});

it('preserves independent inbox previews and unread counts across conversations', function () {
    $first=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $second=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->cleaner->id]])->assertOk()->json('data.id');
    Sanctum::actingAs($this->reception,['hotel:read']);
    $firstMessage=$this->postJson($this->base.'/conversations/'.$first.'/messages',['body'=>'Reception first','client_id'=>(string)Str::uuid()])->assertOk()->json('data.id');
    $this->postJson($this->base.'/conversations/'.$first.'/messages',['body'=>'Reception latest','client_id'=>(string)Str::uuid()])->assertOk();
    Sanctum::actingAs($this->cleaner,['hotel:read']);
    $this->postJson($this->base.'/conversations/'.$second.'/messages',['body'=>'Housekeeping latest','client_id'=>(string)Str::uuid()])->assertOk();
    Sanctum::actingAs($this->manager,['hotel:read','hotel:write']);
    $this->postJson($this->base.'/conversations/'.$first.'/read',['message_id'=>$firstMessage])->assertOk();
    $this->postJson($this->base.'/conversations/'.$second.'/messages',['body'=>'Manager latest','client_id'=>(string)Str::uuid()])->assertOk();
    $rows=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'))->keyBy('id');
    expect($rows->get($first)['latest']['body'])->toBe('Reception latest');
    expect($rows->get($first)['unread'])->toBe(1);
    expect($rows->get($second)['latest']['body'])->toBe('Manager latest');
    expect($rows->get($second)['unread'])->toBe(1);
    expect(collect($rows->get($first)['members'])->pluck('id')->map(fn ($id)=>(int)$id)->contains((int)$this->cleaner->id))->toBeFalse();
});

it('keeps acknowledgement metadata distinct when loading multiple announcements', function () {
    $channel=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'))->firstWhere('title','All Staff')['id'];
    $url=$this->base.'/conversations/'.$channel;
    $first=$this->postJson($url.'/messages',['body'=>'Announcement one','is_announcement'=>true,'client_id'=>(string)Str::uuid()])->assertOk()->json('data.id');
    $second=$this->postJson($url.'/messages',['body'=>'Announcement two','is_announcement'=>true,'client_id'=>(string)Str::uuid()])->assertOk()->json('data.id');
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($this->base.'/conversations')->assertOk();
    $this->postJson($url.'/announcements/'.$first.'/acknowledge')->assertOk();
    $rows=collect($this->getJson($url)->assertOk()->json('data'))->keyBy('id');
    expect($rows->get($first)['acknowledgement']['acknowledged_at'])->not->toBeNull();
    expect($rows->get($second)['acknowledgement']['acknowledged_at'])->toBeNull();
    expect(array_key_exists('total',$rows->get($first)['acknowledgement']))->toBeFalse();
    Sanctum::actingAs($this->manager,['hotel:read','hotel:write']);
    $rows=collect($this->getJson($url)->assertOk()->json('data'))->keyBy('id');
    expect($rows->get($first)['acknowledgement']['total'])->toBe(2);
    expect($rows->get($first)['acknowledgement']['acknowledged'])->toBe(1);
    expect($rows->get($second)['acknowledgement']['acknowledged'])->toBe(0);
});

it('broadcasts only generic private invalidation events and does not rebroadcast retries', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    config(['staff_messaging.realtime.enabled'=>true,'staff_messaging.realtime.app_id'=>'qa-app','staff_messaging.realtime.key'=>'qa-key','staff_messaging.realtime.secret'=>'qa-secret','staff_messaging.realtime.http_url'=>'https://realtime.example.test']);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(['https://realtime.example.test/*'=>\Illuminate\Support\Facades\Http::response([],200)]);
    $url=$this->base.'/conversations/'.$id;
    $payload=['body'=>'Confidential QA content','client_id'=>(string)Str::uuid()];
    $mid=$this->postJson($url.'/messages',$payload)->assertOk()->json('data.id');
    $this->postJson($url.'/messages',$payload)->assertOk()->assertJsonPath('data.id',$mid);
    \Illuminate\Support\Facades\Http::assertSentCount(1);
    \Illuminate\Support\Facades\Http::assertSent(function ($request) {
        $body=json_decode($request->body(),true,512,JSON_THROW_ON_ERROR);
        $channels=$body['channels'];sort($channels);
        $expected=['private-staff.hotel.'.$this->hotel->id.'.user.'.$this->manager->id,'private-staff.hotel.'.$this->hotel->id.'.user.'.$this->reception->id];sort($expected);
        $parts=parse_url($request->url());parse_str($parts['query'],$params);
        $signature=$params['auth_signature'];unset($params['auth_signature']);ksort($params);
        return $request->method()==='POST' && $channels===$expected && $body['data']==='{}'
            && $body['name']==='staff-messaging.changed' && $params['body_md5']===md5($request->body())
            && hash_equals(hash_hmac('sha256',"POST\n".$parts['path']."\n".http_build_query($params,'','&',PHP_QUERY_RFC3986),'qa-secret'),$signature)
            && !str_contains($request->body(),'Confidential QA content');
    });
});

it('preserves committed messaging when the realtime provider fails', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    config(['staff_messaging.realtime.enabled'=>true,'staff_messaging.realtime.app_id'=>'qa-app','staff_messaging.realtime.key'=>'qa-key','staff_messaging.realtime.secret'=>'qa-secret','staff_messaging.realtime.http_url'=>'https://realtime.example.test']);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(['https://realtime.example.test/*'=>\Illuminate\Support\Facades\Http::response('Unavailable',503)]);
    $url=$this->base.'/conversations/'.$id;
    $mid=$this->postJson($url.'/messages',['body'=>'Persist despite outage','client_id'=>(string)Str::uuid()])->assertOk()->json('data.id');
    $this->assertDatabaseHas('staff_messages',['id'=>$mid,'body'=>'Persist despite outage']);
    Sanctum::actingAs($this->reception,['hotel:read']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.body','Persist despite outage');
    \Illuminate\Support\Facades\Http::assertSentCount(1);
});

it('deduplicates realtime recipients and chunks broadcasts within provider limits', function () {
    config(['staff_messaging.realtime.enabled'=>true,'staff_messaging.realtime.app_id'=>'qa-app','staff_messaging.realtime.key'=>'qa-key','staff_messaging.realtime.secret'=>'qa-secret','staff_messaging.realtime.http_url'=>'https://realtime.example.test']);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(['https://realtime.example.test/*'=>\Illuminate\Support\Facades\Http::response([],200)]);
    app(\App\Support\StaffMessagingRealtime::class)->notify((int)$this->hotel->id,array_merge(range(1,205),[1,2,3]));
    \Illuminate\Support\Facades\Http::assertSentCount(3);
    $channels=[];
    foreach (\Illuminate\Support\Facades\Http::recorded() as [$request,$response]) {
        $batch=json_decode($request->body(),true,512,JSON_THROW_ON_ERROR)['channels'];
        expect(count($batch))->toBeLessThanOrEqual(100);
        $channels=array_merge($channels,$batch);
    }
    expect(count($channels))->toBe(205);
    expect(count(array_unique($channels)))->toBe(205);
});

it('disables realtime for insecure or incomplete provider configuration', function () {
    config(['staff_messaging.realtime.enabled'=>true,'staff_messaging.realtime.app_id'=>'qa-app','staff_messaging.realtime.key'=>'qa-key','staff_messaging.realtime.secret'=>'qa-secret']);
    $realtime=app(\App\Support\StaffMessagingRealtime::class);
    foreach (['http://realtime.example.test','https://user:pass@realtime.example.test','https://realtime.example.test?query=1','https://realtime.example.test/path'] as $endpoint) {
        config(['staff_messaging.realtime.http_url'=>$endpoint]);
        expect($realtime->enabled())->toBeFalse();
    }
    config(['staff_messaging.realtime.http_url'=>'https://realtime.example.test','staff_messaging.realtime.secret'=>null]);
    expect($realtime->enabled())->toBeFalse();
});

it('keeps inbox query growth bounded as conversations increase to fifty', function () {
    $seed=function (int $start,int $end) {
        for ($i=$start;$i<=$end;$i++) {
            $id=DB::table('staff_conversations')->insertGetId(['hotel_id'=>$this->hotel->id,'kind'=>'group','title'=>'QA group '.$i,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('staff_conversation_members')->insert([
                ['conversation_id'=>$id,'user_id'=>$this->manager->id,'created_at'=>now(),'updated_at'=>now()],
                ['conversation_id'=>$id,'user_id'=>$this->reception->id,'created_at'=>now(),'updated_at'=>now()],
            ]);
            DB::table('staff_messages')->insert(['conversation_id'=>$id,'sender_id'=>$this->reception->id,'client_id'=>(string)Str::uuid(),'body'=>'QA preview '.$i,'created_at'=>now(),'updated_at'=>now()]);
        }
    };
    $this->getJson($this->base.'/conversations')->assertOk(); // Warm lazy channel provisioning.
    $measure=function () {
        DB::flushQueryLog();DB::enableQueryLog();
        try {
            $response=$this->getJson($this->base.'/conversations')->assertOk();
            return [$response->json('data'),count(DB::getQueryLog())];
        } finally {
            DB::disableQueryLog();DB::flushQueryLog();
        }
    };
    $seed(1,5);
    [$small,$smallQueries]=$measure();
    $seed(6,50);
    [$large,$largeQueries]=$measure();
    expect(collect($small)->where('kind','group')->count())->toBe(5);
    $groups=collect($large)->where('kind','group');
    expect($groups->count())->toBe(50);
    expect($groups->sum('unread'))->toBe(50);
    expect($groups->firstWhere('title','QA group 50')['latest']['body'])->toBe('QA preview 50');
    expect($largeQueries)->toBeLessThanOrEqual($smallQueries+3);
    expect($largeQueries)->toBeLessThanOrEqual(40);
});

it('paginates a thousand messages without omissions or duplicates', function () {
    $id=$this->postJson($this->base.'/conversations',['member_ids'=>[$this->reception->id]])->assertOk()->json('data.id');
    $url=$this->base.'/conversations/'.$id;
    foreach (array_chunk(range(1,1000),100) as $chunk) {
        DB::table('staff_messages')->insert(array_map(fn ($i)=>['conversation_id'=>$id,'sender_id'=>$this->reception->id,'client_id'=>(string)Str::uuid(),'body'=>'QA history '.$i,'created_at'=>now(),'updated_at'=>now()],$chunk));
    }
    $cursor=null;$seen=[];
    for ($page=0;$page<10;$page++) {
        $data=$this->getJson($url.($cursor===null?'':'?before='.$cursor))->assertOk()->assertJsonCount(100,'data')->json('data');
        $ids=array_map('intval',array_column($data,'id'));
        $sorted=$ids;sort($sorted);
        expect($ids)->toBe($sorted);
        expect(array_intersect($seen,$ids))->toBe([]);
        $seen=array_merge($seen,$ids);
        $cursor=$ids[0];
    }
    expect(count(array_unique($seen)))->toBe(1000);
    $this->getJson($url.'?before='.$cursor)->assertOk()->assertJsonCount(0,'data');
    $this->getJson($url.'?q=QA%20history%201000')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.body','QA history 1000');
});

it('keeps announcement history query growth bounded for a full page', function () {
    $id=collect($this->getJson($this->base.'/conversations')->assertOk()->json('data'))->firstWhere('title','All Staff')['id'];
    $url=$this->base.'/conversations/'.$id;
    $seed=function (int $start,int $end) use ($id) {
        for ($i=$start;$i<=$end;$i++) {
            $mid=DB::table('staff_messages')->insertGetId(['conversation_id'=>$id,'sender_id'=>$this->manager->id,'client_id'=>(string)Str::uuid(),'body'=>'QA notice '.$i,'is_announcement'=>true,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('staff_announcement_recipients')->insert([
                ['message_id'=>$mid,'user_id'=>$this->reception->id,'acknowledged_at'=>now()],
                ['message_id'=>$mid,'user_id'=>$this->cleaner->id,'acknowledged_at'=>null],
            ]);
        }
    };
    $measure=function (int $expected) use ($url) {
        DB::flushQueryLog();DB::enableQueryLog();
        try {
            $response=$this->getJson($url)->assertOk()->assertJsonCount($expected,'data');
            $count=count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();DB::flushQueryLog();
        }
        foreach ($response->json('data') as $row) {
            expect($row['acknowledgement']['total'])->toBe(2);
            expect($row['acknowledgement']['acknowledged'])->toBe(1);
        }
        return $count;
    };
    $seed(1,5);$small=$measure(5);
    $seed(6,100);$large=$measure(100);
    expect($large)->toBeLessThanOrEqual($small+3);
    expect($large)->toBeLessThanOrEqual(25);
});

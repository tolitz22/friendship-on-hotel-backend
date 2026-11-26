<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Support\StaffMessageRecordAccess;
use App\Support\StaffMessagingRealtime;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class StaffMessageController
{
    private function active(Hotel $hotel)
    {
        return DB::table('hotel_memberships as hm')->join('users as u','u.id','=','hm.user_id')
            ->where('hm.hotel_id',$hotel->id)->where('hm.is_active',true)->where('u.is_active',true);
    }

    private const CHANNELS = ['all'=>'All Staff','admin'=>'Management','front_desk'=>'Reception','housekeeping'=>'Housekeeping','maintenance'=>'Maintenance'];

    // Membership rows track read state; current role is always authoritative.
    private function channelKeys(Hotel $hotel, int $uid): array
    {
        $roles=$this->active($hotel)->where('u.id',$uid)->pluck('hm.role')->all();
        if (!$roles) return [];
        $keys=in_array('admin',$roles,true)?array_keys(self::CHANNELS):array_merge(['all'],array_intersect(array_keys(self::CHANNELS),$roles));
        return array_map(fn ($key)=>'channel:'.$key,$keys);
    }

    private function ensureChannels(Hotel $hotel, int $uid): void
    {
        $keys=$this->channelKeys($hotel,$uid);
        foreach ($keys as $key) {
            $row=DB::table('staff_conversations')->where('hotel_id',$hotel->id)->where('direct_key',$key)->first();
            if (!$row || !DB::table('staff_conversation_members')->where('conversation_id',$row->id)->where('user_id',$uid)->exists()) {
                DB::transaction(function () use ($hotel,$uid,$key): void {
                    DB::table('hotels')->where('id',$hotel->id)->lockForUpdate()->first();
                    if (!in_array($key,$this->channelKeys($hotel,$uid),true)) return;
                    $id=DB::table('staff_conversations')->where('hotel_id',$hotel->id)->where('direct_key',$key)->value('id');
                    if (!$id) $id=DB::table('staff_conversations')->insertGetId(['hotel_id'=>$hotel->id,'kind'=>'channel','title'=>self::CHANNELS[substr($key,8)],'direct_key'=>$key,'created_at'=>now(),'updated_at'=>now()]);
                    DB::table('staff_conversation_members')->insertOrIgnore(['conversation_id'=>$id,'user_id'=>$uid,'created_at'=>now(),'updated_at'=>now()]);
                },3);
            }
        }
    }

    private function access(Request $request, Hotel $hotel, int $conversation): void
    {
        $keys=$this->channelKeys($hotel,(int)$request->user()->id);
        abort_if(!$keys,404);
        abort_unless(DB::table('staff_conversations as c')
            ->join('staff_conversation_members as m','m.conversation_id','=','c.id')
            ->where('c.hotel_id',$hotel->id)->where('c.id',$conversation)
            ->where('m.user_id',$request->user()->id)->where(fn ($q)=>$q->where('c.kind','!=','channel')->orWhereIn('c.direct_key',$keys))->exists(),404);
    }

    public function realtimeAuth(Request $request, Hotel $hotel): JsonResponse
    {
        $realtime=app(StaffMessagingRealtime::class);
        abort_unless($realtime->enabled(),503,'Realtime unavailable; polling remains enabled.');
        $data=$request->validate(['socket_id'=>['required','string','regex:/^[0-9]+\.[0-9]+$/','max:100'],'channel_name'=>['required','string','max:150']]);
        $uid=(int)$request->user()->id;
        abort_unless($this->active($hotel)->where('u.id',$uid)->exists(),403);
        $channel=$realtime->channel((int)$hotel->id,$uid);
        abort_unless(hash_equals($channel,$data['channel_name']),403);
        return response()->json(['auth'=>$realtime->authorize($data['socket_id'],$channel)]);
    }

    private function notifyConversation(Hotel $hotel, int $conversation): void
    {
        $realtime=app(StaffMessagingRealtime::class);
        if (!$realtime->enabled()) return;
        try {
            $row=DB::table('staff_conversations')->where('hotel_id',$hotel->id)->where('id',$conversation)->first();
            if (!$row) return;
            $eligible=$this->active($hotel);
            if ($row->kind==='channel') {
                $role=substr($row->direct_key,8);
                if ($role!=='all') $eligible->whereIn('hm.role',array_unique([$role,'admin']));
            } else {
                $eligible->whereIn('u.id',DB::table('staff_conversation_members')->where('conversation_id',$conversation)->select('user_id'));
            }
            $realtime->notify((int)$hotel->id,$eligible->distinct()->pluck('u.id')->all());
        } catch (\Throwable $e) { report($e); }
    }

    public function records(Request $request, Hotel $hotel): JsonResponse
    {
        $data=$request->validate(['type'=>['required','in:room,booking,housekeeping,maintenance'],'q'=>['nullable','string','max:120']]);
        $access=app(StaffMessageRecordAccess::class);
        $type=$data['type'];
        $query=$access->query($request,$hotel,$type);
        abort_unless($query,403);
        $term=trim($data['q']??'');
        if ($term!=='') {
            $column=match ($type) {'room'=>'number','booking'=>'reference',default=>null};
            $query->where(function ($q) use ($term,$column): void {
                $q->whereRaw('1 = 0');
                if (preg_match('/^[1-9][0-9]{0,17}$/D',$term)) $q->orWhere('id',$term);
                if ($column) $q->orWhereRaw("LOWER($column) LIKE ? ESCAPE '!'",['%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($term)).'%']);
            });
        }
        return response()->json(['data'=>$query->orderByDesc('id')->limit(30)->get($access->columns($type))->map(fn ($row)=>$access->present($type,$row))]);
    }

    private function presentReference(Request $request, Hotel $hotel, object $row): void
    {
        $row->record=null;
        $row->record_unavailable=false;
        if ($row->record_type!==null && $row->record_id!==null) {
            $row->record=app(StaffMessageRecordAccess::class)->resolve($request,$hotel,$row->record_type,(string)$row->record_id);
            $row->record_unavailable=$row->record===null;
        }
        unset($row->record_type,$row->record_id);
    }

    private function announcementMeta(Request $request, Hotel $hotel, object $row): void
    {
        $row->is_announcement=(bool)$row->is_announcement;
        $row->handover=$row->handover===null?null:json_decode($row->handover,true,512,JSON_THROW_ON_ERROR);
        if (!$row->is_announcement && !$row->handover) return;
        $recipients=DB::table('staff_announcement_recipients')->where('message_id',$row->id);
        $mine=(clone $recipients)->where('user_id',$request->user()->id)->first();
        $row->acknowledgement=['required'=>$mine!==null,'acknowledged_at'=>$mine?->acknowledged_at];
        if (($row->handover && (string)$row->sender_id===(string)$request->user()->id) || ($this->active($hotel)->where('u.id',$request->user()->id)->where('hm.role','admin')->exists() && $request->user()->tokenCan('hotel:write'))) {
            $row->acknowledgement['total']=$recipients->count();
            $row->acknowledgement['acknowledged']=(clone $recipients)->whereNotNull('acknowledged_at')->count();
        }
    }

    public function acknowledge(Request $request, Hotel $hotel, int $conversation, int $message): JsonResponse
    {
        $this->access($request,$hotel,$conversation);
        abort_unless(DB::table('staff_messages')->where('conversation_id',$conversation)->where('id',$message)->where(fn ($q)=>$q->where('is_announcement',true)->orWhereNotNull('handover'))->exists(),404);
        $recipient=DB::table('staff_announcement_recipients')->where('message_id',$message)->where('user_id',$request->user()->id);
        abort_unless($recipient->exists(),404);
        if ($recipient->whereNull('acknowledged_at')->update(['acknowledged_at'=>now()])) $this->notifyConversation($hotel,$conversation);
        return response()->json(['data'=>true]);
    }

    public function directory(Request $request, Hotel $hotel): JsonResponse
    {
        return response()->json(['data'=>$this->active($hotel)->where('u.id','!=',$request->user()->id)
            ->orderBy('u.name')->get(['u.id','u.name','hm.role'])]);
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $uid=$request->user()->id;
        abort_unless($this->active($hotel)->where('u.id',$uid)->exists(),404);
        $this->ensureChannels($hotel,(int)$uid);
        $keys=$this->channelKeys($hotel,(int)$uid);
        $rows=DB::table('staff_conversations as c')->join('staff_conversation_members as m','m.conversation_id','=','c.id')
            ->where('c.hotel_id',$hotel->id)->where('m.user_id',$uid)
            ->where(fn ($q)=>$q->where('c.kind','!=','channel')->orWhereIn('c.direct_key',$keys))
            ->orderByDesc('c.updated_at')->orderByDesc('c.id')->get(['c.*','m.last_read_message_id']);
        $rows->each(function ($c) use ($uid,$hotel,$request): void {
            $c->members=DB::table('staff_conversation_members as m')->join('users as u','u.id','=','m.user_id')
                ->where('m.conversation_id',$c->id)->whereIn('u.id',$this->active($hotel)->select('u.id'))->get(['u.id','u.name','m.last_read_message_id']);
            if ($c->kind==='channel') {
                $eligible=$this->active($hotel);
                $role=substr($c->direct_key,8);
                if ($role!=='all') $eligible->whereIn('hm.role',array_unique([$role,'admin']));
                $c->members=$eligible->leftJoin('staff_conversation_members as cm',function ($join) use ($c) { $join->on('cm.user_id','=','u.id')->where('cm.conversation_id',$c->id); })->get(['u.id','u.name','cm.last_read_message_id']);
            }
            $c->latest=DB::table('staff_messages')->where('conversation_id',$c->id)->orderByDesc('id')->first();
            if ($c->latest) { $this->presentReference($request,$hotel,$c->latest); $this->announcementMeta($request,$hotel,$c->latest); }
            $c->unread=DB::table('staff_messages')->where('conversation_id',$c->id)
                ->where('id','>',$c->last_read_message_id)->where('sender_id','!=',$uid)->count();
        });
        return response()->json(['data'=>$rows]);
    }

    public function create(Request $request, Hotel $hotel): JsonResponse
    {
        $data=$request->validate(['member_ids'=>['required','array','min:1','max:50'],
            'member_ids.*'=>['required','integer','min:1','distinct'],'title'=>['nullable','string','max:120']]);
        $uid=(int)$request->user()->id;
        $ids=array_values(array_unique(array_map('intval',$data['member_ids'])));
        abort_if(in_array($uid,$ids,true),422,'Select other staff members.');
        $id=DB::transaction(function () use ($hotel,$uid,$ids,$data) {
            DB::table('hotels')->where('id',$hotel->id)->lockForUpdate()->first();
            abort_unless($this->active($hotel)->whereIn('u.id',array_merge([$uid],$ids))->distinct()->count('u.id')===count($ids)+1,422,'Select active staff from this hotel.');
            $members=array_merge([$uid],$ids); sort($members);
            $direct=count($members)===2;
            $key=$direct?implode(':',$members):null;
            if ($direct && ($existing=DB::table('staff_conversations')->where('hotel_id',$hotel->id)->where('direct_key',$key)->value('id'))) return $existing;
            $title=trim($data['title']??'');
            abort_if(!$direct && $title==='',422,'Enter a group name.');
            $id=DB::table('staff_conversations')->insertGetId(['hotel_id'=>$hotel->id,'kind'=>$direct?'direct':'group','title'=>$direct?null:$title,'direct_key'=>$key,'created_at'=>now(),'updated_at'=>now()]);
            foreach ($members as $member) DB::table('staff_conversation_members')->insert(['conversation_id'=>$id,'user_id'=>$member,'created_at'=>now(),'updated_at'=>now()]);
            return $id;
        },3);
        $this->notifyConversation($hotel,(int)$id);
        return response()->json(['data'=>['id'=>$id]]);
    }

    public function show(Request $request, Hotel $hotel, int $conversation): JsonResponse
    {
        $this->access($request,$hotel,$conversation);
        $data=$request->validate(['before'=>['nullable','integer','min:1'],'q'=>['nullable','string','max:120']]);
        $query=DB::table('staff_messages as m')->leftJoin('users as u','u.id','=','m.sender_id')->leftJoin('staff_messages as reply',function ($join) { $join->on('reply.id','=','m.reply_to_id')->on('reply.conversation_id','=','m.conversation_id'); })->where('m.conversation_id',$conversation);
        if (!empty($data['before'])) $query->where('m.id','<',$data['before']);
        if (isset($data['q']) && trim($data['q'])!=='') $query->whereRaw("LOWER(m.body) LIKE ? ESCAPE '!'",['%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower(trim($data['q']))).'%']);
        $rows=$query->orderByDesc('m.id')->limit(100)->get(['m.*','u.name as sender_name','reply.body as reply_body'])->reverse()->values();
        $attachments=DB::table('staff_message_attachments')->whereIn('message_id',$rows->pluck('id'))->get(['id','message_id','filename','mime','size'])->keyBy('message_id');
        $rows->each(function ($row) use ($attachments,$request,$hotel): void {
            $row->attachment=$attachments->get($row->id);
            $this->presentReference($request,$hotel,$row);
            $this->announcementMeta($request,$hotel,$row);
        });
        return response()->json(['data'=>$rows]);
    }

    public function send(Request $request, Hotel $hotel, int $conversation): JsonResponse
    {
        $this->access($request,$hotel,$conversation);
        $data=$request->validate(['body'=>['nullable','string','max:4000'],'attachment'=>['nullable','array:filename,content','required_array_keys:filename,content'],'attachment.filename'=>['required_with:attachment','string','max:200'],'attachment.content'=>['required_with:attachment','string','max:2796204'],'client_id'=>['required','uuid'],'reply_to_id'=>['nullable','integer','min:1'],'record_type'=>['nullable','required_with:record_id','in:room,booking,housekeeping,maintenance'],'record_id'=>['nullable','required_with:record_type','integer','min:1'],'is_announcement'=>['sometimes','boolean'],'handover'=>['nullable','array:incoming_shift,outstanding,reminders','required_array_keys:incoming_shift,outstanding'],'handover.incoming_shift'=>['required_with:handover','string','max:80'],'handover.outstanding'=>['required_with:handover','string','max:2000'],'handover.reminders'=>['nullable','string','max:1000']]);
        $body=trim($data['body']??'');
        $attachment=null;
        if (!empty($data['attachment'])) {
            $file=$data['attachment'];
            $bytes=base64_decode($file['content'],true);
            abort_if($bytes===false || strlen($bytes)===0 || strlen($bytes)>2097152,422,'File must be at most 2 MB.');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
            abort_unless(isset($extensions[$mime]),422,'Only JPEG, PNG, WebP and PDF files are allowed.');
            $name=preg_replace('/[^a-zA-Z0-9 _.-]/','_',pathinfo(basename($file['filename']),PATHINFO_FILENAME));
            $attachment=['filename'=>substr($name?:'attachment',0,180).'.'.$extensions[$mime],'mime'=>$mime,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'encoded_content'=>base64_encode($bytes)];
        }
        $announcement=(bool)($data['is_announcement']??false);
        $handover=null;
        if (!empty($data['handover'])) {
            $shift=trim($data['handover']['incoming_shift']);
            $outstanding=trim($data['handover']['outstanding']);
            $reminders=trim($data['handover']['reminders']??'');
            abort_if($shift==='' || $outstanding==='',422,'Enter incoming shift and outstanding work (or None).');
            abort_if($announcement,422,'Choose either announcement or shift handover.');
            $handover=json_encode(['incoming_shift'=>$shift,'outstanding'=>$outstanding,'reminders'=>$reminders],JSON_THROW_ON_ERROR);
            // Keep a searchable, human-readable summary for existing clients.
            $body='Shift handover: '.$shift."\nOutstanding work: ".$outstanding.($reminders!==''?"\nReminders: ".$reminders:'').($body!==''?"\nNotes: ".$body:'');
            abort_if(mb_strlen($body)>4000,422,'Handover and notes must fit within 4000 characters.');
        }
        abort_if($announcement && $body==='',422,'Enter announcement text.');
        $recordType=$data['record_type']??null;
        $recordId=$data['record_id']??null;
        abort_if(($recordType===null)!==($recordId===null),422,'Select a complete record reference.');
        abort_if($body==='' && !$attachment && !$recordType,422,'Enter a message, attach a file or share a record.');
        $uid=$request->user()->id;
        [$message,$created]=DB::transaction(function () use ($request,$hotel,$conversation,$data,$body,$uid,$attachment,$recordType,$recordId,$announcement,$handover) {
            DB::table('staff_conversations')->where('hotel_id',$hotel->id)->where('id',$conversation)->lockForUpdate()->first();
            $this->access($request,$hotel,$conversation);
            $channel=DB::table('staff_conversations')->where('hotel_id',$hotel->id)->where('id',$conversation)->first();
            if ($announcement) {
                abort_unless($request->user()->tokenCan('hotel:write') && $this->active($hotel)->where('u.id',$uid)->where('hm.role','admin')->exists(),403);
                abort_unless($channel->kind==='channel',422,'Announcements must be published in a hotel channel.');
            }
            if ($handover!==null) abort_unless($channel->kind==='channel',422,'Publish shift handovers in a hotel channel.');
            if ($recordType!==null) abort_unless(app(StaffMessageRecordAccess::class)->resolve($request,$hotel,$recordType,(string)$recordId),422,'Record unavailable for sharing.');
            $reply=$data['reply_to_id']??null;
            abort_if($reply!==null && !DB::table('staff_messages')->where('conversation_id',$conversation)->where('id',$reply)->exists(),422,'Reply must reference a message in this conversation.');
            $query=DB::table('staff_messages')->where('conversation_id',$conversation)->where('sender_id',$uid)->where('client_id',$data['client_id']);
            if ($existing=$query->first()) { $prior=DB::table('staff_message_attachments')->where('message_id',$existing->id)->first(); abort_if(($prior->sha256??null)!==($attachment['sha256']??null) || ($prior->filename??null)!==($attachment['filename']??null) || $existing->body!==$body || (bool)$existing->is_announcement!==$announcement || $existing->handover!==$handover || $existing->record_type!==$recordType || (string)$existing->record_id!==(string)$recordId || (string)$existing->reply_to_id!==(string)$reply,409,'Message identifier already used.'); return [$existing,false];}
            $id=DB::table('staff_messages')->insertGetId(['conversation_id'=>$conversation,'sender_id'=>$uid,'client_id'=>$data['client_id'],'body'=>$body,'reply_to_id'=>$reply,'record_type'=>$recordType,'record_id'=>$recordId,'is_announcement'=>$announcement,'handover'=>$handover,'created_at'=>now(),'updated_at'=>now()]);
            if ($announcement || $handover!==null) {
                $eligible=$this->active($hotel)->where('u.id','!=',$uid);
                $role=substr($channel->direct_key,8);
                if ($role!=='all') $eligible->whereIn('hm.role',array_unique([$role,'admin']));
                foreach ($eligible->distinct()->pluck('u.id') as $recipient) DB::table('staff_announcement_recipients')->insert(['message_id'=>$id,'user_id'=>$recipient]);
            }
            if ($attachment) DB::table('staff_message_attachments')->insert(['message_id'=>$id,...$attachment]);
            DB::table('staff_conversations')->where('id',$conversation)->update(['updated_at'=>now()]);
            return [DB::table('staff_messages')->where('id',$id)->first(),true];
        },3);
        if ($created) $this->notifyConversation($hotel,$conversation);
        $this->presentReference($request,$hotel,$message);
        $this->announcementMeta($request,$hotel,$message);
        return response()->json(['data'=>$message]);
    }

    public function download(Request $request, Hotel $hotel, int $conversation, int $attachment): \Illuminate\Http\Response
    {
        $this->access($request,$hotel,$conversation);
        $file=DB::table('staff_message_attachments as a')->join('staff_messages as m','m.id','=','a.message_id')->where('m.conversation_id',$conversation)->where('a.id',$attachment)->first(['a.*']);
        abort_unless($file,404);
        $bytes=base64_decode($file->encoded_content,true);
        abort_unless($bytes!==false && strlen($bytes)===(int)$file->size && hash_equals($file->sha256,hash('sha256',$bytes)),409,'Attachment integrity check failed.');
        return response($bytes,200,[
            'Content-Type'=>'application/octet-stream',
            'Content-Disposition'=>'attachment; filename="'.$file->filename.'"',
            'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store',
        ]);
    }

    public function read(Request $request, Hotel $hotel, int $conversation): JsonResponse
    {
        $this->access($request,$hotel,$conversation);
        $data=$request->validate(['message_id'=>['required','integer','min:1']]);
        abort_unless(DB::table('staff_messages')->where('conversation_id',$conversation)->where('id',$data['message_id'])->exists(),422);
        $changed=DB::table('staff_conversation_members')->where('conversation_id',$conversation)->where('user_id',$request->user()->id)
            ->where('last_read_message_id','<',$data['message_id'])->update(['last_read_message_id'=>$data['message_id'],'updated_at'=>now()]);
        if ($changed) $this->notifyConversation($hotel,$conversation);
        return response()->json(['data'=>true]);
    }
}

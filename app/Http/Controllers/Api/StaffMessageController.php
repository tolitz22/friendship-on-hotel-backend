<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
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
        abort_unless(DB::table('staff_conversations as c')
            ->join('staff_conversation_members as m','m.conversation_id','=','c.id')
            ->where('c.hotel_id',$hotel->id)->where('c.id',$conversation)
            ->where('m.user_id',$request->user()->id)->where(fn ($q)=>$q->where('c.kind','!=','channel')->orWhereIn('c.direct_key',$keys))->exists(),404);
    }

    public function directory(Request $request, Hotel $hotel): JsonResponse
    {
        return response()->json(['data'=>$this->active($hotel)->where('u.id','!=',$request->user()->id)
            ->orderBy('u.name')->get(['u.id','u.name','hm.role'])]);
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $uid=$request->user()->id;
        $this->ensureChannels($hotel,(int)$uid);
        $keys=$this->channelKeys($hotel,(int)$uid);
        $rows=DB::table('staff_conversations as c')->join('staff_conversation_members as m','m.conversation_id','=','c.id')
            ->where('c.hotel_id',$hotel->id)->where('m.user_id',$uid)
            ->where(fn ($q)=>$q->where('c.kind','!=','channel')->orWhereIn('c.direct_key',$keys))
            ->orderByDesc('c.updated_at')->orderByDesc('c.id')->get(['c.*','m.last_read_message_id']);
        $rows->each(function ($c) use ($uid,$hotel): void {
            $c->members=DB::table('staff_conversation_members as m')->join('users as u','u.id','=','m.user_id')
                ->where('m.conversation_id',$c->id)->get(['u.id','u.name','m.last_read_message_id']);
            if ($c->kind==='channel') {
                $eligible=$this->active($hotel);
                $role=substr($c->direct_key,8);
                if ($role!=='all') $eligible->whereIn('hm.role',array_unique([$role,'admin']));
                $c->members=$eligible->leftJoin('staff_conversation_members as cm',function ($join) use ($c) { $join->on('cm.user_id','=','u.id')->where('cm.conversation_id',$c->id); })->get(['u.id','u.name','cm.last_read_message_id']);
            }
            $c->latest=DB::table('staff_messages')->where('conversation_id',$c->id)->orderByDesc('id')->first();
            $c->unread=DB::table('staff_messages')->where('conversation_id',$c->id)
                ->where('id','>',$c->last_read_message_id)->where('sender_id','!=',$uid)->count();
        });
        return response()->json(['data'=>$rows]);
    }

    public function create(Request $request, Hotel $hotel): JsonResponse
    {
        $data=$request->validate(['member_ids'=>['required','array','min:1','max:50'],
            'member_ids.*'=>['required','integer','distinct'],'title'=>['nullable','string','max:120']]);
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
        return response()->json(['data'=>['id'=>$id]]);
    }

    public function show(Request $request, Hotel $hotel, int $conversation): JsonResponse
    {
        $this->access($request,$hotel,$conversation);
        $data=$request->validate(['before'=>['nullable','integer','min:1'],'q'=>['nullable','string','max:120']]);
        $query=DB::table('staff_messages as m')->leftJoin('users as u','u.id','=','m.sender_id')->leftJoin('staff_messages as reply',function ($join) { $join->on('reply.id','=','m.reply_to_id')->on('reply.conversation_id','=','m.conversation_id'); })->where('m.conversation_id',$conversation);
        if (!empty($data['before'])) $query->where('m.id','<',$data['before']);
        if (!empty($data['q'])) $query->where('m.body','like','%'.$data['q'].'%');
        $rows=$query->orderByDesc('m.id')->limit(100)->get(['m.*','u.name as sender_name','reply.body as reply_body'])->reverse()->values();
        $attachments=DB::table('staff_message_attachments')->whereIn('message_id',$rows->pluck('id'))->get(['id','message_id','filename','mime','size'])->keyBy('message_id');
        $rows->each(fn ($row)=>$row->attachment=$attachments->get($row->id));
        return response()->json(['data'=>$rows]);
    }

    public function send(Request $request, Hotel $hotel, int $conversation): JsonResponse
    {
        $this->access($request,$hotel,$conversation);
        $data=$request->validate(['body'=>['nullable','string','max:4000'],'attachment'=>['nullable','array'],'attachment.filename'=>['required_with:attachment','string','max:200'],'attachment.content'=>['required_with:attachment','string','max:2796204'],'client_id'=>['required','uuid'],'reply_to_id'=>['nullable','integer','min:1']]);
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
        abort_if($body==='' && !$attachment,422,'Enter a message or attach a file.');
        $uid=$request->user()->id;
        $message=DB::transaction(function () use ($request,$hotel,$conversation,$data,$body,$uid,$attachment) {
            DB::table('staff_conversations')->where('id',$conversation)->lockForUpdate()->first();
            $this->access($request,$hotel,$conversation);
            $reply=$data['reply_to_id']??null;
            abort_if($reply!==null && !DB::table('staff_messages')->where('conversation_id',$conversation)->where('id',$reply)->exists(),422,'Reply must reference a message in this conversation.');
            $query=DB::table('staff_messages')->where('conversation_id',$conversation)->where('sender_id',$uid)->where('client_id',$data['client_id']);
            if ($existing=$query->first()) { $prior=DB::table('staff_message_attachments')->where('message_id',$existing->id)->first(); abort_if(($prior->sha256??null)!==($attachment['sha256']??null) || ($prior->filename??null)!==($attachment['filename']??null) || $existing->body!==$body || (string)$existing->reply_to_id!==(string)$reply,409,'Message identifier already used.'); return $existing;}
            $id=DB::table('staff_messages')->insertGetId(['conversation_id'=>$conversation,'sender_id'=>$uid,'client_id'=>$data['client_id'],'body'=>$body,'reply_to_id'=>$reply,'created_at'=>now(),'updated_at'=>now()]);
            if ($attachment) DB::table('staff_message_attachments')->insert(['message_id'=>$id,...$attachment]);
            DB::table('staff_conversations')->where('id',$conversation)->update(['updated_at'=>now()]);
            return DB::table('staff_messages')->where('id',$id)->first();
        },3);
        return response()->json(['data'=>$message]);
    }

    public function download(Request $request, Hotel $hotel, int $conversation, int $attachment): \Illuminate\Http\Response
    {
        $this->access($request,$hotel,$conversation);
        $file=DB::table('staff_message_attachments as a')->join('staff_messages as m','m.id','=','a.message_id')->where('m.conversation_id',$conversation)->where('a.id',$attachment)->first(['a.*']);
        abort_unless($file,404);
        return response(base64_decode($file->encoded_content,true),200,[
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
        DB::table('staff_conversation_members')->where('conversation_id',$conversation)->where('user_id',$request->user()->id)
            ->where('last_read_message_id','<',$data['message_id'])->update(['last_read_message_id'=>$data['message_id'],'updated_at'=>now()]);
        return response()->json(['data'=>true]);
    }
}

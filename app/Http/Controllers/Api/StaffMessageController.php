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

    private function access(Request $request, Hotel $hotel, int $conversation): void
    {
        abort_unless(DB::table('staff_conversations as c')
            ->join('staff_conversation_members as m','m.conversation_id','=','c.id')
            ->where('c.hotel_id',$hotel->id)->where('c.id',$conversation)
            ->where('m.user_id',$request->user()->id)->exists(),404);
    }

    public function directory(Request $request, Hotel $hotel): JsonResponse
    {
        return response()->json(['data'=>$this->active($hotel)->where('u.id','!=',$request->user()->id)
            ->orderBy('u.name')->get(['u.id','u.name','hm.role'])]);
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $uid=$request->user()->id;
        $rows=DB::table('staff_conversations as c')->join('staff_conversation_members as m','m.conversation_id','=','c.id')
            ->where('c.hotel_id',$hotel->id)->where('m.user_id',$uid)
            ->orderByDesc('c.updated_at')->orderByDesc('c.id')->get(['c.*','m.last_read_message_id']);
        $rows->each(function ($c) use ($uid): void {
            $c->members=DB::table('staff_conversation_members as m')->join('users as u','u.id','=','m.user_id')
                ->where('m.conversation_id',$c->id)->get(['u.id','u.name','m.last_read_message_id']);
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
        return response()->json(['data'=>$query->orderByDesc('m.id')->limit(100)->get(['m.*','u.name as sender_name','reply.body as reply_body'])->reverse()->values()]);
    }

    public function send(Request $request, Hotel $hotel, int $conversation): JsonResponse
    {
        $this->access($request,$hotel,$conversation);
        $data=$request->validate(['body'=>['required','string','max:4000'],'client_id'=>['required','uuid'],'reply_to_id'=>['nullable','integer','min:1']]);
        $body=trim($data['body']); abort_if($body==='',422,'Enter a message.');
        $uid=$request->user()->id;
        $message=DB::transaction(function () use ($request,$hotel,$conversation,$data,$body,$uid) {
            DB::table('staff_conversations')->where('id',$conversation)->lockForUpdate()->first();
            $this->access($request,$hotel,$conversation);
            $reply=$data['reply_to_id']??null;
            abort_if($reply!==null && !DB::table('staff_messages')->where('conversation_id',$conversation)->where('id',$reply)->exists(),422,'Reply must reference a message in this conversation.');
            $query=DB::table('staff_messages')->where('conversation_id',$conversation)->where('sender_id',$uid)->where('client_id',$data['client_id']);
            if ($existing=$query->first()) {abort_if($existing->body!==$body || (string)$existing->reply_to_id!==(string)$reply,409,'Message identifier already used.'); return $existing;}
            $id=DB::table('staff_messages')->insertGetId(['conversation_id'=>$conversation,'sender_id'=>$uid,'client_id'=>$data['client_id'],'body'=>$body,'reply_to_id'=>$reply,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('staff_conversations')->where('id',$conversation)->update(['updated_at'=>now()]);
            return DB::table('staff_messages')->where('id',$id)->first();
        },3);
        return response()->json(['data'=>$message]);
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HotelStaffController
{
    private const ROLES = ['admin','front_desk','housekeeping','maintenance'];

    private function staffList(Hotel $hotel): array
    {
        return $hotel->memberships()->with('user:id,name,email,is_active')
            ->orderBy('user_id')->get()
            ->map(fn ($m): array => [
                'userId'=>(string)$m->user_id,
                'name'=>$m->user->name,
                'email'=>$m->user->email,
                'role'=>$m->role,
                'isActive'=>(bool)$m->is_active,
                'accountActive'=>(bool)$m->user->is_active,
            ])->all();
    }

    public function index(Hotel $hotel): JsonResponse
    {
        return response()->json(['data'=>$this->staffList($hotel)]);
    }

    public function attach(Request $request, Hotel $hotel): JsonResponse
    {
        $data=$request->validate([
            'email'=>['required','email','max:255'],
            'role'=>['required',Rule::in(self::ROLES)],
        ]);
        $user=User::whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])->first();
        if (!$user || !$user->is_active) {
            throw ValidationException::withMessages(['email'=>['An existing active account with this email is required.']]);
        }

        DB::transaction(function () use ($hotel,$user,$data,$request): void {
            DB::table('hotels')->where('id',$hotel->id)->lockForUpdate()->first();
            if (HotelMembership::where('hotel_id',$hotel->id)->where('user_id',$user->id)->exists()) {
                throw ValidationException::withMessages(['email'=>['This account already belongs to the hotel.']]);
            }
            HotelMembership::create([
                'hotel_id'=>$hotel->id,'user_id'=>$user->id,
                'role'=>$data['role'],'is_active'=>true,
            ]);
            $this->audit($hotel,(int)$request->user()->id,(int)$user->id,'attached',null,['role'=>$data['role'],'isActive'=>true]);
        });
        return response()->json(['data'=>$this->staffList($hotel)],201);
    }

    public function history(Request $request, Hotel $hotel): JsonResponse
    {
        $input=$request->validate(['per_page'=>['sometimes','integer','min:1','max:50']]);
        $page=DB::table('hotel_staff_events')
            ->leftJoin('users as audit_actor','audit_actor.id','=','hotel_staff_events.actor_id')
            ->leftJoin('users as audit_target','audit_target.id','=','hotel_staff_events.target_user_id')
            ->where('hotel_staff_events.hotel_id',$hotel->id)->orderByDesc('hotel_staff_events.id')
            ->paginate((int)($input['per_page']??20),['hotel_staff_events.*','audit_actor.name as actor_name','audit_target.name as target_name']);
        return response()->json([
            'data'=>$page->getCollection()->map(fn(object $row):array=>[
                'id'=>(string)$row->id,'actorId'=>(string)$row->actor_id,
                'actorName'=>$row->actor_name,'targetName'=>$row->target_name,
                'targetUserId'=>(string)$row->target_user_id,'action'=>$row->action,
                'before'=>$row->before_state?json_decode($row->before_state,true):null,
                'after'=>json_decode($row->after_state,true),'createdAt'=>$row->created_at,
            ])->values(),
            'meta'=>['page'=>$page->currentPage(),'perPage'=>$page->perPage(),
                'total'=>$page->total(),'totalPages'=>$page->lastPage()],
        ]);
    }

    public function update(Request $request, Hotel $hotel, string $userId): JsonResponse
    {
        $data=$request->validate([
            'role'=>['sometimes','required',Rule::in(self::ROLES)],
            'isActive'=>['sometimes','required','boolean'],
        ]);

        DB::transaction(function () use ($request,$hotel,$userId,$data): void {
            // Lock the hotel to serialize membership changes and preserve at least one administrator.
            DB::table('hotels')->where('id',$hotel->id)->lockForUpdate()->first();
            $m=HotelMembership::where('hotel_id',$hotel->id)
                ->where('user_id',$userId)->lockForUpdate()->firstOrFail();
            $nextRole=$data['role']??$m->role;
            $nextActive=$data['isActive']??$m->is_active;
            if ((string)$request->user()->id===(string)$m->user_id
                && (!$nextActive || $nextRole!=='admin')) {
                throw ValidationException::withMessages(['role'=>['You cannot remove your own administrator access.']]);
            }
            if ($m->role==='admin' && $m->is_active && (!$nextActive || $nextRole!=='admin')) {
                $count=HotelMembership::where('hotel_id',$hotel->id)
                    ->where('role','admin')->where('is_active',true)->count();
                if ($count<=1) {
                    throw ValidationException::withMessages(['role'=>['The hotel must keep an active administrator.']]);
                }
            }
            $before=['role'=>$m->role,'isActive'=>(bool)$m->is_active];
            $after=['role'=>$nextRole,'isActive'=>(bool)$nextActive];
            if ($before!==$after) {
                $m->update(['role'=>$nextRole,'is_active'=>$nextActive]);
                $this->audit($hotel,(int)$request->user()->id,(int)$m->user_id,'updated',$before,$after);
            }
        });

        return response()->json(['data'=>$this->staffList($hotel)]);
    }

    private function audit(Hotel $hotel,int $actor,int $target,string $action,?array $before,array $after): void
    {
        DB::table('hotel_staff_events')->insert([
            'hotel_id'=>$hotel->id,'actor_id'=>$actor,'target_user_id'=>$target,
            'action'=>$action,'before_state'=>$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),
            'after_state'=>json_encode($after,JSON_THROW_ON_ERROR),'created_at'=>now(),
        ]);
    }
}

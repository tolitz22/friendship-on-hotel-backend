<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Http\Middleware\EnsureHotelPermission;
use App\Models\Hotel;
use App\Support\TaskStaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class LostFoundController
{
    private function access(Request $request, Hotel $hotel): bool
    {
        $member = $request->user()->memberships()->where('hotel_id',$hotel->id)->where('is_active',true)->first();
        abort_unless($request->user()->is_active && $member !== null && in_array($member->role,['admin','housekeeping'],true),403);
        return $member->role === 'admin';
    }

    private function query(Request $request, Hotel $hotel)
    {
        $admin = $this->access($request,$hotel);
        $query = DB::table('lost_found_items as i')->join('rooms as r',function ($join): void {
            $join->on('r.id','=','i.room_id')->on('r.hotel_id','=','i.hotel_id');
        })->where('i.hotel_id',$hotel->id)->select('i.*','r.number as room_number');
        if (!$admin) $query->where('i.reported_by',$request->user()->id);
        return $query;
    }

    private function present(object $row): array
    {
        return ['id'=>(string)$row->id,'hotelId'=>(string)$row->hotel_id,'roomId'=>(string)$row->room_id,
            'roomNumber'=>$row->room_number,'description'=>$row->description,'storageLocation'=>$row->storage_location,
            'status'=>$row->status,'reportedBy'=>(string)$row->reported_by,'revision'=>(int)$row->revision,
            'reportedAt'=>$row->created_at,'updatedAt'=>$row->updated_at];
    }

    private function state(object $row): array
    {
        return ['status'=>$row->status,'storageLocation'=>$row->storage_location];
    }

    private function event(Hotel $hotel, int $id, int $actor, string $action, ?array $before, array $after, ?string $note): void
    {
        DB::table('lost_found_events')->insert(['hotel_id'=>$hotel->id,'item_id'=>$id,'actor_id'=>$actor,'action'=>$action,
            'before_state'=>$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),
            'after_state'=>json_encode($after,JSON_THROW_ON_ERROR),'note'=>$note,'created_at'=>now()]);
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $query=$this->query($request,$hotel);
        $input=$request->validate(['status'=>['sometimes',Rule::in(['stored','returned','disposed'])],
            'page'=>['sometimes','integer','min:1']]);
        if (isset($input['status'])) $query->where('i.status',$input['status']);
        $page=$query->orderByDesc('i.id')->paginate(20);
        return response()->json(['data'=>$page->getCollection()->map(fn ($row)=>$this->present($row))->values(),
            'meta'=>['hotelId'=>(string)$hotel->id,'page'=>$page->currentPage(),'total'=>$page->total(),'totalPages'=>$page->lastPage()]]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $admin=$this->access($request,$hotel);
        if ($admin) abort_unless(TaskStaffAccess::isAdmin($request,$hotel),403);
        else {
            $member=$request->user()->memberships()->where('hotel_id',$hotel->id)->where('is_active',true)->firstOrFail();
            abort_unless(in_array('housekeeping.assigned',EnsureHotelPermission::effectiveForMembership($member),true),403);
        }
        $data=$request->validate(['roomId'=>['required','integer','min:1'],'description'=>['required','string','max:500'],
            'storageLocation'=>['required','string','max:160'],'requestKey'=>['required','uuid']]);
        abort_unless($hotel->rooms()->whereKey($data['roomId'])->exists(),422,'Select a room at this hotel.');
        $result=DB::transaction(function () use ($hotel,$request,$data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $old=DB::table('lost_found_items')->where('hotel_id',$hotel->id)->where('reported_by',$request->user()->id)
                ->where('request_key',$data['requestKey'])->first();
            if ($old) {
                abort_if((string)$old->room_id!==(string)$data['roomId']||$old->description!==trim($data['description']),409,'Report key already used.');
                return ['id'=>$old->id,'created'=>false];
            }
            $id=DB::table('lost_found_items')->insertGetId(['hotel_id'=>$hotel->id,'room_id'=>$data['roomId'],
                'reported_by'=>$request->user()->id,'request_key'=>$data['requestKey'],'description'=>trim($data['description']),
                'storage_location'=>trim($data['storageLocation']),'status'=>'stored','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
            $row=DB::table('lost_found_items')->where('id',$id)->first();
            $this->event($hotel,$id,$request->user()->id,'registered',null,$this->state($row),null);
            return ['id'=>$id,'created'=>true];
        });
        return response()->json(['data'=>$this->present($this->query($request,$hotel)->where('i.id',$result['id'])->first())],$result['created']?201:200);
    }

    public function update(Request $request, Hotel $hotel, string $item): JsonResponse
    {
        abort_unless(TaskStaffAccess::isAdmin($request,$hotel),403);
        $data=$request->validate(['expectedRevision'=>['required','integer','min:1'],
            'action'=>['required',Rule::in(['move','return','dispose'])],
            'storageLocation'=>['required_if:action,move','nullable','string','max:160'],
            'note'=>['required','string','min:5','max:2000']]);
        DB::transaction(function () use ($request,$hotel,$item,$data): void {
            $row=DB::table('lost_found_items')->where('hotel_id',$hotel->id)->where('id',$item)->lockForUpdate()->first();
            abort_if($row===null,404);
            abort_if((int)$row->revision!==(int)$data['expectedRevision'],409,'Item changed. Refresh before saving.');
            abort_if($row->status!=='stored',409,'This item already has a final custody outcome.');
            $changes=match($data['action']){
                'move'=>['storage_location'=>trim($data['storageLocation'])],
                'return'=>['status'=>'returned'],
                'dispose'=>['status'=>'disposed'],
            };
            $next=(object)array_merge((array)$row,$changes);
            DB::table('lost_found_items')->where('id',$row->id)->update($changes+['revision'=>$row->revision+1,'updated_at'=>now()]);
            $this->event($hotel,(int)$row->id,$request->user()->id,$data['action'],$this->state($row),$this->state($next),trim($data['note']));
        });
        return response()->json(['data'=>$this->present($this->query($request,$hotel)->where('i.id',$item)->first())]);
    }

    public function history(Request $request, Hotel $hotel, string $item): JsonResponse
    {
        abort_unless($this->query($request,$hotel)->where('i.id',$item)->exists(),404);
        $admin=$this->access($request,$hotel);
        $page=DB::table('lost_found_events')->where('hotel_id',$hotel->id)->where('item_id',$item)->orderByDesc('id')->paginate(20);
        return response()->json(['data'=>$page->getCollection()->map(fn ($row)=>[
            'id'=>(string)$row->id,'actorId'=>(string)$row->actor_id,'action'=>$row->action,
            'before'=>$row->before_state===null?null:json_decode($row->before_state,true,512,JSON_THROW_ON_ERROR),
            'after'=>json_decode($row->after_state,true,512,JSON_THROW_ON_ERROR),
            'note'=>$admin?$row->note:null,'createdAt'=>$row->created_at,
        ])->values(),'meta'=>['page'=>$page->currentPage(),'totalPages'=>$page->lastPage()]]);
    }
}

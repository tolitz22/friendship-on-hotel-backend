<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Support\TaskStaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MaintenanceWorkOrderController
{
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    private const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];
    private const TRANSITIONS = [
        'open' => ['in_progress', 'resolved'],
        'in_progress' => ['resolved'],
        'resolved' => ['closed'],
        'closed' => [],
    ];

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'hotelId' => (string) $row->hotel_id,
            'roomId' => (string) $row->room_id,
            'roomNumber' => $row->room_number,
            'title' => $row->title,
            'description' => $row->description,
            'priority' => $row->priority,
            'status' => $row->status,
            'assignedTo' => $row->assigned_to,
            'assignedUserId' => $row->assigned_user_id===null?null:(string)$row->assigned_user_id,
            'affectsRoom' => (bool) $row->affects_room,
            'revision' => (int) $row->revision,
            'reportedAt' => $row->created_at,
            'resolvedAt' => $row->resolved_at,
        ];
    }

    private function orderQuery(Hotel $hotel)
    {
        return DB::table('maintenance_work_orders as wo')
            ->join('rooms as r', function ($join): void {
                $join->on('r.id','=','wo.room_id')->on('r.hotel_id','=','wo.hotel_id');
            })
            ->where('wo.hotel_id', $hotel->id)
            ->select('wo.*','r.number as room_number');
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:100'],
            'status' => ['sometimes',Rule::in(self::STATUSES)],
            'priority' => ['sometimes',Rule::in(self::PRIORITIES)],
        ]);
        $query = $this->orderQuery($hotel);
        if (isset($params['status'])) $query->where('wo.status', $params['status']);
        if (isset($params['priority'])) $query->where('wo.priority', $params['priority']);
        $page = $query->orderByDesc('wo.id')->paginate((int)($params['per_page']??20));
        return response()->json([
            'data'=>$page->getCollection()->map(fn(object $row):array=>$this->present($row))->values(),
            'meta'=>[
                'page'=>$page->currentPage(),'perPage'=>$page->perPage(),
                'total'=>$page->total(),'totalPages'=>$page->lastPage(),
                'affectsRoomDoesNotBlockInventory'=>true,
            ],
        ]);
    }

    public function show(Hotel $hotel, string $order): JsonResponse
    {
        $row = $this->orderQuery($hotel)->where('wo.id',$order)->first();
        abort_if($row===null,404);
        return response()->json(['data'=>$this->present($row)]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'roomId'=>['required','integer','min:1'],
            'title'=>['required','string','max:160'],
            'description'=>['required','string','max:5000'],
            'priority'=>['required',Rule::in(self::PRIORITIES)],
            'assignedTo'=>['sometimes','nullable','string','max:120'],
            'assignedUserId'=>['sometimes','nullable','integer','min:1'],
            'affectsRoom'=>['sometimes','boolean'],
            'note'=>['sometimes','nullable','string','max:2000'],
        ]);
        $room = $hotel->rooms()->find($data['roomId']);
        if (!$room) throw ValidationException::withMessages(['roomId'=>['Select a room belonging to this hotel.']]);
        foreach (['title','description'] as $key) {
            $data[$key]=trim($data[$key]);
            if ($data[$key]==='') throw ValidationException::withMessages([$key=>['This field cannot be blank.']]);
        }

        TaskStaffAccess::verifyAssignee($hotel,isset($data['assignedUserId'])?(int)$data['assignedUserId']:null,'maintenance');
        $id = DB::transaction(function () use ($hotel,$request,$data): int {
            app(\App\Domain\Reservations\ReadinessWriteGuard::class)->lockHotel($hotel);
            $id = DB::table('maintenance_work_orders')->insertGetId([
                'hotel_id'=>$hotel->id,'room_id'=>$data['roomId'],
                'title'=>$data['title'],'description'=>$data['description'],
                'priority'=>$data['priority'],'status'=>'open',
                'assigned_to'=>$data['assignedTo']??null,
                'assigned_user_id'=>$data['assignedUserId']??null,
                'affects_room'=>$data['affectsRoom']??false,
                'revision'=>1,'created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $after = DB::table('maintenance_work_orders')->where('id',$id)->first();
            $this->event($hotel,$id,$request->user()->id,'created',null,$this->state($after),$data['note']??null);
            return $id;
        });

        return response()->json(['data'=>$this->present($this->orderQuery($hotel)->where('wo.id',$id)->first())],201);
    }

    public function update(Request $request, Hotel $hotel, string $order): JsonResponse
    {
        $data = $request->validate([
            'expectedRevision'=>['required','integer','min:1'],
            'status'=>['sometimes',Rule::in(self::STATUSES)],
            'priority'=>['sometimes',Rule::in(self::PRIORITIES)],
            'assignedTo'=>['sometimes','nullable','string','max:120'],
            'assignedUserId'=>['sometimes','nullable','integer','min:1'],
            'affectsRoom'=>['sometimes','boolean'],
            'note'=>['sometimes','nullable','string','max:2000'],
        ]);
        $response = DB::transaction(function () use ($request,$hotel,$order,$data): array {
            app(\App\Domain\Reservations\ReadinessWriteGuard::class)->lockHotel($hotel);
            $row = DB::table('maintenance_work_orders')
                ->where('hotel_id',$hotel->id)->where('id',$order)->lockForUpdate()->first();
            abort_if($row===null,404);
            if (!TaskStaffAccess::canOperate($request,$hotel,$row,'maintenance')) abort(403);
            if (!TaskStaffAccess::isAdmin($request,$hotel)
                && (array_key_exists('priority',$data)||array_key_exists('assignedTo',$data)||array_key_exists('assignedUserId',$data)||array_key_exists('affectsRoom',$data))) abort(403);
            if (array_key_exists('assignedUserId',$data)) TaskStaffAccess::verifyAssignee($hotel,$data['assignedUserId']===null?null:(int)$data['assignedUserId'],'maintenance');
            if ((int)$row->revision !== (int)$data['expectedRevision']) return ['conflict'=>true];
            $before = $this->state($row);
            $changes=[];
            foreach (['status'=>'status','priority'=>'priority','assignedTo'=>'assigned_to','assignedUserId'=>'assigned_user_id','affectsRoom'=>'affects_room'] as $input=>$column) {
                if (array_key_exists($input,$data)) $changes[$column]=$data[$input];
            }
            if (isset($changes['status']) && $changes['status'] !== $row->status
                && !in_array($changes['status'],self::TRANSITIONS[$row->status]??[],true)) {
                throw ValidationException::withMessages(['status'=>['The requested status transition is not supported.']]);
            }
            $next = (object) array_merge((array)$row,$changes);
            $after = $this->state($next);
            $note=isset($data['note'])?trim($data['note']):null;
            if ($before === $after && (!$note || $note==='')) return ['conflict'=>false];
            if (isset($changes['status']) && $changes['status'] === 'resolved') $changes['resolved_at']=now();
            if ($before !== $after) {
                $changes['revision']=$row->revision+1;
                $changes['updated_at']=now();
                DB::table('maintenance_work_orders')->where('id',$row->id)->update($changes);
            } else {
                DB::table('maintenance_work_orders')->where('id',$row->id)->update(['revision'=>$row->revision+1,'updated_at'=>now()]);
            }
            $this->event($hotel,(int)$row->id,$request->user()->id,'updated',$before,$after,$note);
            return ['conflict'=>false];
        });
        if ($response['conflict']) {
            return response()->json(['message'=>'Work order was updated by another administrator. Reload before saving.'],409);
        }
        return $this->show($hotel,$order);
    }

    public function history(Request $request, Hotel $hotel, string $order): JsonResponse
    {
        abort_unless(DB::table('maintenance_work_orders')->where('hotel_id',$hotel->id)->where('id',$order)->exists(),404);
        $input=$request->validate(['per_page'=>['sometimes','integer','min:1','max:50']]);
        $page=DB::table('maintenance_work_order_events')
            ->leftJoin('users as audit_actor','audit_actor.id','=','maintenance_work_order_events.actor_id')
            ->where('maintenance_work_order_events.hotel_id',$hotel->id)
            ->where('maintenance_work_order_events.work_order_id',$order)
            ->orderByDesc('maintenance_work_order_events.id')
            ->paginate((int)($input['per_page']??20),['maintenance_work_order_events.*','audit_actor.name as actor_name']);
        return response()->json([
            'data'=>$page->getCollection()->map(fn(object $event):array=>[
                'id'=>(string)$event->id,
                'actorId'=>(string)$event->actor_id,
                'actorName'=>$event->actor_name,
                'action'=>$event->action,
                'before'=>$event->before_state?json_decode($event->before_state,true):null,
                'after'=>json_decode($event->after_state,true),
                'note'=>$event->note,
                'createdAt'=>$event->created_at,
            ])->values(),
            'meta'=>['page'=>$page->currentPage(),'perPage'=>$page->perPage(),
                'total'=>$page->total(),'totalPages'=>$page->lastPage()],
        ]);
    }

    private function state(object $row): array
    {
        return ['status'=>$row->status,'priority'=>$row->priority,
            'assignedTo'=>$row->assigned_to,
            'assignedUserId'=>$row->assigned_user_id===null?null:(string)$row->assigned_user_id,'affectsRoom'=>(bool)$row->affects_room];
    }

    private function event(Hotel $hotel,int $id,int $actor,string $action,?array $before,array $after,?string $note): void
    {
        DB::table('maintenance_work_order_events')->insert([
            'hotel_id'=>$hotel->id,'work_order_id'=>$id,'actor_id'=>$actor,'action'=>$action,
            'before_state'=>$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),
            'after_state'=>json_encode($after,JSON_THROW_ON_ERROR),
            'note'=>$note,'created_at'=>now(),
        ]);
    }
}

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

final class HousekeepingTaskController
{
    private const TYPES = ['checkout_clean', 'stayover', 'inspection'];
    private const STATUSES = ['todo', 'in_progress', 'done'];
    private const TRANSITIONS = [
        'todo' => ['in_progress', 'done'],
        'in_progress' => ['done'],
        'done' => [],
    ];

    private function scoped(Hotel $hotel)
    {
        return DB::table('housekeeping_tasks as t')
            ->join('rooms as r', function ($join): void {
                $join->on('r.id','=','t.room_id')->on('r.hotel_id','=','t.hotel_id');
            })
            ->where('t.hotel_id', $hotel->id)
            ->select('t.*','r.number as room_number');
    }

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'hotelId' => (string) $row->hotel_id,
            'roomId' => (string) $row->room_id,
            'roomNumber' => $row->room_number,
            'type' => $row->type,
            'status' => $row->status,
            'assignedTo' => $row->assigned_to,
            'assignedUserId' => $row->assigned_user_id===null?null:(string)$row->assigned_user_id,
            'dueAt' => $row->due_at,
            'completedAt' => $row->completed_at,
            'revision' => (int) $row->revision,
            'createdAt' => $row->created_at,
        ];
    }

    private function state(object $row): array
    {
        return [
            'status' => $row->status,
            'assignedTo' => $row->assigned_to,
            'assignedUserId' => $row->assigned_user_id===null?null:(string)$row->assigned_user_id,
            'dueAt' => $row->due_at,
        ];
    }

    private function event(Hotel $hotel, int $task, int $actor, string $action, ?array $before, array $after, ?string $note): void
    {
        DB::table('housekeeping_task_events')->insert([
            'hotel_id'=>$hotel->id, 'task_id'=>$task, 'actor_id'=>$actor,
            'action'=>$action,
            'before_state'=>$before===null?null:json_encode($before, JSON_THROW_ON_ERROR),
            'after_state'=>json_encode($after, JSON_THROW_ON_ERROR),
            'note'=>$note, 'created_at'=>now(),
        ]);
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $input=$request->validate([
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','min:1','max:100'],
            'status'=>['sometimes',Rule::in(self::STATUSES)],
            'type'=>['sometimes',Rule::in(self::TYPES)],
        ]);
        $query=$this->scoped($hotel);
        if (isset($input['status'])) $query->where('t.status',$input['status']);
        if (isset($input['type'])) $query->where('t.type',$input['type']);
        $page=$query->orderByDesc('t.id')->paginate((int)($input['per_page']??20));
        return response()->json([
            'data'=>$page->getCollection()->map(fn (object $row): array=>$this->present($row))->values(),
            'meta'=>[
                'page'=>$page->currentPage(), 'perPage'=>$page->perPage(),
                'total'=>$page->total(), 'totalPages'=>$page->lastPage(),
                'determinesRoomReadiness'=>false,
            ],
        ]);
    }

    public function show(Hotel $hotel, string $task): JsonResponse
    {
        $row=$this->scoped($hotel)->where('t.id',$task)->first();
        abort_if($row===null,404);
        return response()->json(['data'=>$this->present($row)]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data=$request->validate([
            'roomId'=>['required','integer','min:1'],
            'type'=>['required',Rule::in(self::TYPES)],
            'assignedTo'=>['sometimes','nullable','string','max:120'],
            'assignedUserId'=>['sometimes','nullable','integer','min:1'],
            'dueAt'=>['sometimes','nullable','date_format:Y-m-d H:i:s'],
            'note'=>['sometimes','nullable','string','max:2000'],
        ]);
        if (!$hotel->rooms()->whereKey($data['roomId'])->exists()) {
            throw ValidationException::withMessages(['roomId'=>['Select a room belonging to this hotel.']]);
        }
        TaskStaffAccess::verifyAssignee($hotel,isset($data['assignedUserId'])?(int)$data['assignedUserId']:null,'housekeeping');
        $id=DB::transaction(function () use ($hotel,$request,$data): int {
            app(\App\Domain\Reservations\ReadinessWriteGuard::class)->lockHotel($hotel);
            $id=DB::table('housekeeping_tasks')->insertGetId([
                'hotel_id'=>$hotel->id, 'room_id'=>$data['roomId'], 'type'=>$data['type'],
                'status'=>'todo', 'assigned_to'=>$data['assignedTo']??null,
                'assigned_user_id'=>$data['assignedUserId']??null,
                'due_at'=>$data['dueAt']??null, 'revision'=>1,
                'created_by'=>$request->user()->id, 'created_at'=>now(), 'updated_at'=>now(),
            ]);
            $row=DB::table('housekeeping_tasks')->where('id',$id)->first();
            $this->event($hotel,$id,(int)$request->user()->id,'created',null,$this->state($row),$data['note']??null);
            return $id;
        });
        return response()->json(['data'=>$this->present($this->scoped($hotel)->where('t.id',$id)->first())],201);
    }

    public function update(Request $request, Hotel $hotel, string $task): JsonResponse
    {
        $data=$request->validate([
            'expectedRevision'=>['required','integer','min:1'],
            'status'=>['sometimes',Rule::in(self::STATUSES)],
            'assignedTo'=>['sometimes','nullable','string','max:120'],
            'assignedUserId'=>['sometimes','nullable','integer','min:1'],
            'dueAt'=>['sometimes','nullable','date_format:Y-m-d H:i:s'],
            'note'=>['sometimes','nullable','string','max:2000'],
        ]);
        $result=DB::transaction(function () use ($request,$hotel,$task,$data): array {
            app(\App\Domain\Reservations\ReadinessWriteGuard::class)->lockHotel($hotel);
            $row=DB::table('housekeeping_tasks')
                ->where('hotel_id',$hotel->id)->where('id',$task)->lockForUpdate()->first();
            abort_if($row===null,404);
            if (!TaskStaffAccess::canOperate($request,$hotel,$row,'housekeeping')) abort(403);
            if (!TaskStaffAccess::isAdmin($request,$hotel)
                && (array_key_exists('assignedTo',$data)||array_key_exists('assignedUserId',$data)||array_key_exists('dueAt',$data))) abort(403);
            if (array_key_exists('assignedUserId',$data)) TaskStaffAccess::verifyAssignee($hotel,$data['assignedUserId']===null?null:(int)$data['assignedUserId'],'housekeeping');
            if ((int)$row->revision!==(int)$data['expectedRevision']) return ['conflict'=>true];

            $changes=[];
            foreach (['status'=>'status','assignedTo'=>'assigned_to','assignedUserId'=>'assigned_user_id','dueAt'=>'due_at'] as $key=>$column) {
                if (array_key_exists($key,$data)) $changes[$column]=$data[$key];
            }
            if (isset($changes['status']) && $changes['status']!==$row->status
                && !in_array($changes['status'],self::TRANSITIONS[$row->status]??[],true)) {
                throw ValidationException::withMessages(['status'=>['This housekeeping transition is not supported.']]);
            }
            // Compare normalized values to avoid a false edit caused by formatting nullable timestamps.
            $next=(object)array_merge((array)$row,$changes);
            $before=$this->state($row);
            $after=$this->state($next);
            $note=isset($data['note'])?trim($data['note']):null;
            if ($before===$after && ($note===null||$note==='')) return ['conflict'=>false];
            $changes['revision']=(int)$row->revision+1;
            $changes['updated_at']=now();
            if (($changes['status']??null)==='done' && $row->status!=='done') $changes['completed_at']=now();
            DB::table('housekeeping_tasks')->where('id',$row->id)->update($changes);
            $this->event($hotel,(int)$row->id,(int)$request->user()->id,'updated',$before,$after,$note);
            return ['conflict'=>false];
        });
        if ($result['conflict']) {
            return response()->json(['message'=>'This task was updated by another administrator. Reload before saving.'],409);
        }
        return $this->show($hotel,$task);
    }

    public function history(Request $request, Hotel $hotel, string $task): JsonResponse
    {
        abort_unless(DB::table('housekeeping_tasks')->where('hotel_id',$hotel->id)->where('id',$task)->exists(),404);
        $input=$request->validate(['per_page'=>['sometimes','integer','min:1','max:50']]);
        $page=DB::table('housekeeping_task_events')
            ->leftJoin('users as audit_actor','audit_actor.id','=','housekeeping_task_events.actor_id')
            ->where('housekeeping_task_events.hotel_id',$hotel->id)
            ->where('housekeeping_task_events.task_id',$task)
            ->orderByDesc('housekeeping_task_events.id')
            ->paginate((int)($input['per_page']??20),['housekeeping_task_events.*','audit_actor.name as actor_name']);
        return response()->json([
            'data'=>$page->getCollection()->map(fn (object $row): array=>[
                'id'=>(string)$row->id, 'actorId'=>(string)$row->actor_id,
                'actorName'=>$row->actor_name,
                'action'=>$row->action,
                'before'=>$row->before_state?json_decode($row->before_state,true):null,
                'after'=>json_decode($row->after_state,true),
                'note'=>$row->note, 'createdAt'=>$row->created_at,
            ])->values(),
            'meta'=>[
                'page'=>$page->currentPage(), 'perPage'=>$page->perPage(),
                'total'=>$page->total(), 'totalPages'=>$page->lastPage(),
            ],
        ]);
    }
}

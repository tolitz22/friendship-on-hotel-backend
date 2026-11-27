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

    private const CHECKLISTS = [
        'checkout_clean' => [
            'trash' => 'Remove trash and check for belongings',
            'linens' => 'Replace bed linens',
            'bathroom' => 'Clean and disinfect bathroom',
            'towels' => 'Replace towels',
            'amenities' => 'Replenish toiletries and supplies',
            'floors' => 'Clean floors',
            'surfaces' => 'Clean and disinfect surfaces',
            'fixtures' => 'Check lights, air conditioning and visible damage',
        ],
        'stayover' => [
            'trash' => 'Remove trash',
            'bed' => 'Make bed and refresh linens as requested',
            'bathroom' => 'Clean bathroom',
            'towels' => 'Refresh towels as requested',
            'amenities' => 'Replenish supplies as needed',
            'floors' => 'Clean floors and accessible surfaces',
        ],
    ];

    private function checklist(object $row): array
    {
        $template = self::CHECKLISTS[$row->type] ?? [];
        $saved = isset($row->cleaning_checklist) ? json_decode($row->cleaning_checklist, true, 512, JSON_THROW_ON_ERROR) : [];
        return array_map(fn (string $key, string $label): array => [
            'key' => $key, 'label' => $label,
            'checkedBy' => $saved[$key]['checkedBy'] ?? null,
            'checkedAt' => $saved[$key]['checkedAt'] ?? null,
        ], array_keys($template), array_values($template));
    }

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
            'checklist' => $this->checklist($row),
            'completedBy' => isset($row->completed_by) ? (string) $row->completed_by : null,
        ];
    }

    private function state(object $row): array
    {
        return [
            'status' => $row->status,
            'assignedTo' => $row->assigned_to,
            'assignedUserId' => $row->assigned_user_id===null?null:(string)$row->assigned_user_id,
            'dueAt' => $row->due_at,
            'checkedItems' => array_values(array_map(fn ($item) => $item['key'], array_filter($this->checklist($row), fn ($item) => $item['checkedAt'] !== null))),
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

    /** Personal operational snapshot: no guest, booking, or financial details. */
    public function dashboard(Request $request, Hotel $hotel): JsonResponse
    {
        $staff = $request->user();
        abort_unless($staff->memberships()->where('hotel_id', $hotel->id)
            ->where('role', 'housekeeping')->where('is_active', true)->exists(), 403);

        $localNow = now($hotel->timezone ?: 'Asia/Manila');
        $today = $localNow->toDateString();
        // completed_at is written in the application timezone by now().
        $start = $localNow->copy()->startOfDay()->setTimezone(config('app.timezone'))->toDateTimeString();
        $end = $localNow->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone'))->toDateTimeString();
        $dueNow = $localNow->toDateTimeString(); // task due_at is a hotel-local wall time
        $mine = $this->scoped($hotel)->where('t.assigned_user_id', $staff->id);
        $active = (clone $mine)->whereIn('t.status', ['todo', 'in_progress']);
        $arrivals = DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('lifecycle_state', 'confirmed')->where('check_in', $today)
            ->whereNotNull('assigned_room_id')->select('assigned_room_id')->distinct();
        $queue = (clone $active)->leftJoinSub($arrivals, 'arrival', function ($join): void {
            $join->on('arrival.assigned_room_id', '=', 't.room_id');
        });
        $urgentRooms = (clone $queue)->whereNotNull('arrival.assigned_room_id')
            ->where('t.type', '!=', 'stayover')->distinct()->count('t.room_id');
        $rows = $queue->addSelect('arrival.assigned_room_id as arrival_room')
            ->orderByRaw("CASE WHEN arrival.assigned_room_id IS NOT NULL AND t.type != 'stayover' THEN 0 WHEN t.due_at IS NOT NULL AND t.due_at < ? THEN 1 WHEN t.status = 'in_progress' THEN 2 ELSE 3 END", [$dueNow])
            ->orderByRaw('CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('t.due_at')->orderBy('t.id')->limit(12)->get();

        $todo = (clone $mine)->where('t.status', 'todo')->count();
        $inProgress = (clone $mine)->where('t.status', 'in_progress')->count();

        return response()->json(['data' => [
            'hotelId' => (string) $hotel->id,
            'userId' => (string) $staff->id,
            'hotelDate' => $today,
            'summary' => [
                'todo' => $todo,
                'inProgress' => $inProgress,
                'urgentRooms' => $urgentRooms,
                'completedToday' => (clone $mine)->where('t.status', 'done')
                    ->where('t.completed_at', '>=', $start)->where('t.completed_at', '<', $end)->count(),
            ],
            'tasks' => $rows->map(fn (object $row): array => [
                ...$this->present($row),
                'priority' => $row->arrival_room !== null && $row->type !== 'stayover' ? 'arrival_today'
                    : ($row->due_at !== null && (string) $row->due_at < $dueNow ? 'overdue'
                        : ($row->status === 'in_progress' ? 'in_progress' : 'normal')),
            ])->values(),
            'activeTotal' => $todo + $inProgress,
        ]]);
    }

    /** Cleaning completion and inspection evidence are distinct from current room readiness. */
    private function pendingInspection($query)
    {
        return $query->where('t.type', 'checkout_clean')->where('t.status', 'done')
            ->whereNotExists(function ($inspection): void {
                $inspection->selectRaw('1')->from('room_readiness_inspections as i')
                    ->whereColumn('i.hotel_id', 't.hotel_id')->whereColumn('i.room_id', 't.room_id')
                    ->where('i.status', 'approved')
                    ->whereColumn('i.latest_housekeeping_task_id', '>=', 't.id')
                    ->whereColumn('i.created_at', '>=', 't.completed_at');
            });
    }

    public function managerReport(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate(['days'=>['sometimes','integer','min:1','max:14']]);
        $days = (int) ($input['days'] ?? 7);
        $localNow = now($hotel->timezone ?: 'Asia/Manila');
        $first = $localNow->copy()->startOfDay()->subDays($days - 1);
        $zone = config('app.timezone');
        $start = $first->copy()->setTimezone($zone)->toDateTimeString();
        $end = $localNow->copy()->addDay()->startOfDay()->setTimezone($zone)->toDateTimeString();
        $dueNow = $localNow->toDateTimeString();
        $daily = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $first->copy()->addDays($i)->toDateString();
            $daily[$date] = ['date'=>$date,'checkoutCompleted'=>0,'stayoverCompleted'=>0,'inspectionsCompleted'=>0,
                'cleaningSamples'=>0,'cleaningMinutes'=>0,'turnoverSamples'=>0,'turnoverMinutes'=>0];
        }
        $staff = [];
        $staffRow = static fn ($id) => ['userId'=>$id === null ? null : (string)$id,
            'todo'=>0,'inProgress'=>0,'overdue'=>0,'completed'=>0,'checkoutCompleted'=>0,'stayoverCompleted'=>0,
            'cleaningSamples'=>0,'cleaningMinutes'=>0];
        $active = $this->scoped($hotel)->whereIn('t.status',['todo','in_progress']);
        $work = (clone $active)->select('t.assigned_user_id')
            ->selectRaw("SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END) as todo")
            ->selectRaw("SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as progress")
            ->selectRaw("SUM(CASE WHEN t.due_at < ? THEN 1 ELSE 0 END) as overdue",[$dueNow])
            ->groupBy('t.assigned_user_id')->get();
        foreach ($work as $row) {
            $key = $row->assigned_user_id ?? 'unknown';
            $staff[$key] = $staffRow($row->assigned_user_id);
            $staff[$key]['todo'] = (int)$row->todo;
            $staff[$key]['inProgress'] = (int)$row->progress;
            $staff[$key]['overdue'] = (int)$row->overdue;
        }
        $completed = DB::table('housekeeping_tasks as t')->where('t.hotel_id',$hotel->id)
            ->where('t.status','done')->where('t.completed_at','>=',$start)->where('t.completed_at','<',$end)
            ->select('t.id','t.type','t.completed_at','t.completed_by')
            ->selectSub(DB::table('housekeeping_task_events as e')->whereColumn('e.hotel_id','t.hotel_id')
                ->whereColumn('e.task_id','t.id')->where('e.action','updated')
                ->where('e.before_state->status','todo')->where('e.after_state->status','in_progress')
                ->selectRaw('MIN(e.created_at)'), 'started_at')
            ->selectSub(DB::table('housekeeping_task_events as e')->whereColumn('e.hotel_id','t.hotel_id')
                ->whereColumn('e.task_id','t.id')->where('e.action','updated')->where('e.after_state->status','done')
                ->where('e.before_state->status','!=','done')->orderBy('e.id')->limit(1)->select('e.actor_id'), 'legacy_actor')
            ->lazyById(500,'t.id','id');
        foreach ($completed as $row) {
            $date = \Illuminate\Support\Carbon::parse($row->completed_at,$zone)->setTimezone($localNow->timezone)->toDateString();
            if (!isset($daily[$date])) continue;
            $actor = $row->completed_by ?? $row->legacy_actor;
            $key = $actor ?? 'unknown';
            $staff[$key] ??= $staffRow($actor);
            $staff[$key]['completed']++;
            $kind = match ($row->type) {'checkout_clean'=>'checkoutCompleted','stayover'=>'stayoverCompleted',default=>null};
            if ($kind === null) continue; // Standalone inspection is not cleaning.
            $staff[$key][$kind]++;
            $daily[$date][$kind]++;
            if ($row->started_at !== null && $row->started_at <= $row->completed_at) {
                $minutes = \Illuminate\Support\Carbon::parse($row->started_at,$zone)->diffInSeconds(\Illuminate\Support\Carbon::parse($row->completed_at,$zone))/60;
                $daily[$date]['cleaningSamples']++;
                $daily[$date]['cleaningMinutes'] += $minutes;
                $staff[$key]['cleaningSamples']++;
                $staff[$key]['cleaningMinutes'] += $minutes;
            }
        }
        // First approved inspection for the exact checkout turnover, not a later unrelated inspection.
        $turnovers = DB::table('reservation_turnover_links as l')
            ->join('housekeeping_tasks as t',function ($join): void {
                $join->on('t.id','=','l.housekeeping_task_id')->on('t.hotel_id','=','l.hotel_id');
            })->join('room_readiness_inspections as i',function ($join): void {
                $join->on('i.hotel_id','=','l.hotel_id')->on('i.room_id','=','t.room_id')->on('i.latest_turnover_link_id','=','l.id');
            })->join('room_readiness_events as e',function ($join): void {
                $join->on('e.hotel_id','=','i.hotel_id')->on('e.inspection_id','=','i.id');
            })->where('l.hotel_id',$hotel->id)->where('t.status','done')->where('e.action','approved')
            ->whereColumn('i.latest_housekeeping_task_id','>=','t.id')->whereColumn('e.created_at','>=','t.completed_at')
            ->whereColumn('e.created_at','>=','l.created_at')->groupBy('l.id','l.created_at')
            ->select('l.id','l.created_at as checkout_at')->selectRaw('MIN(e.created_at) as ready_at')
            ->havingRaw('MIN(e.created_at) >= ? AND MIN(e.created_at) < ?',[$start,$end])->get();
        foreach ($turnovers as $row) {
            $date = \Illuminate\Support\Carbon::parse($row->ready_at,$zone)->setTimezone($localNow->timezone)->toDateString();
            if (!isset($daily[$date])) continue;
            $daily[$date]['turnoverSamples']++;
            $daily[$date]['turnoverMinutes'] += \Illuminate\Support\Carbon::parse($row->checkout_at,$zone)->diffInSeconds(\Illuminate\Support\Carbon::parse($row->ready_at,$zone))/60;
        }
        $inspections = DB::table('room_readiness_events')->where('hotel_id',$hotel->id)->where('action','approved')
            ->where('created_at','>=',$start)->where('created_at','<',$end)->select('id','created_at')->lazyById(500);
        foreach ($inspections as $row) {
            $date = \Illuminate\Support\Carbon::parse($row->created_at,$zone)->setTimezone($localNow->timezone)->toDateString();
            if (isset($daily[$date])) $daily[$date]['inspectionsCompleted']++;
        }
        $sum = static fn (string $key) => array_sum(array_column($daily,$key));
        $average = static fn ($minutes,$samples) => $samples > 0 ? round($minutes/$samples,1) : null;
        $summary = [
            'todo'=>array_sum(array_column($staff,'todo')),'inProgress'=>array_sum(array_column($staff,'inProgress')),
            'overdue'=>array_sum(array_column($staff,'overdue')),
            'unassigned'=>(clone $active)->whereNull('t.assigned_user_id')->count(),
            'pendingInspection'=>$this->pendingInspection($this->scoped($hotel))->count(),
            'checkoutCompleted'=>$sum('checkoutCompleted'),'stayoverCompleted'=>$sum('stayoverCompleted'),
            'inspectionsCompleted'=>$sum('inspectionsCompleted'),
            'cleaningSamples'=>$sum('cleaningSamples'),'turnoverSamples'=>$sum('turnoverSamples'),
            'averageCleaningMinutes'=>$average($sum('cleaningMinutes'),$sum('cleaningSamples')),
            'averageTurnoverMinutes'=>$average($sum('turnoverMinutes'),$sum('turnoverSamples')),
        ];
        $staff = array_map(static function ($row) use ($average) {
            $row['averageCleaningMinutes']=$average($row['cleaningMinutes'],$row['cleaningSamples']);
            unset($row['cleaningMinutes']);
            return $row;
        },array_values($staff));
        $daily = array_map(static function ($row) use ($average) {
            $row['averageCleaningMinutes']=$average($row['cleaningMinutes'],$row['cleaningSamples']);
            $row['averageTurnoverMinutes']=$average($row['turnoverMinutes'],$row['turnoverSamples']);
            unset($row['cleaningMinutes'],$row['turnoverMinutes']);
            return $row;
        },array_values($daily));
        return response()->json(['data'=>['hotelId'=>(string)$hotel->id,'hotelDate'=>$localNow->toDateString(),
            'days'=>$days,'summary'=>$summary,'staff'=>$staff,'daily'=>$daily],
            'meta'=>['snapshotOnly'=>true,'durationBasis'=>'recorded_events']]);
    }

    public function workload(Hotel $hotel): JsonResponse
    {
        $rows = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
            ->whereIn('status', ['todo', 'in_progress'])->whereNotNull('assigned_user_id')
            ->select('assigned_user_id')
            ->selectRaw("SUM(CASE WHEN status = 'todo' THEN 1 ELSE 0 END) as todo")
            ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
            ->groupBy('assigned_user_id')->get();
        return response()->json(['data' => $rows->map(fn (object $row): array => [
            'userId' => (string) $row->assigned_user_id,
            'todo' => (int) $row->todo, 'inProgress' => (int) $row->in_progress,
        ])->values(), 'meta' => ['hotelId' => (string) $hotel->id]]);
    }

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $input=$request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'mine' => ['sometimes', 'boolean'],
            'queue' => ['sometimes', Rule::in(['active', 'arrival_today', 'overdue', 'unassigned', 'pending_inspection'])],
            'sort' => ['sometimes', Rule::in(['priority'])],
            'assignee' => ['sometimes', 'integer', 'min:1'],
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','min:1','max:100'],
            'status'=>['sometimes',Rule::in(self::STATUSES)],
            'type'=>['sometimes',Rule::in(self::TYPES)],
        ]);
        $query=$this->scoped($hotel);
        if (isset($input['status'])) $query->where('t.status',$input['status']);
        if (isset($input['type'])) $query->where('t.type',$input['type']);
        if ($request->boolean('mine')) {
            $query->where('t.assigned_user_id', $request->user()->id);
        }
        if (isset($input['assignee'])) $query->where('t.assigned_user_id', $input['assignee']);
        $localNow = now($hotel->timezone ?: 'Asia/Manila');
        $dueNow = $localNow->toDateTimeString();
        $arrivals = DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('lifecycle_state', 'confirmed')->where('check_in', $localNow->toDateString())
            ->whereNotNull('assigned_room_id')->select('assigned_room_id')->distinct();
        $query->leftJoinSub($arrivals, 'arrival', function ($join): void {
            $join->on('arrival.assigned_room_id', '=', 't.room_id');
        })->addSelect('arrival.assigned_room_id as arrival_room');
        $queue = $input['queue'] ?? null;
        if (in_array($queue, ['active', 'arrival_today', 'overdue', 'unassigned'], true)) {
            $query->whereIn('t.status', ['todo', 'in_progress']);
        }
        if ($queue === 'arrival_today') $query->whereNotNull('arrival.assigned_room_id')->where('t.type', '!=', 'stayover');
        if ($queue === 'overdue') $query->where('t.due_at', '<', $dueNow);
        if ($queue === 'unassigned') $query->whereNull('t.assigned_user_id');
        if ($queue === 'pending_inspection') $this->pendingInspection($query);
        if (isset($input['q']) && trim($input['q']) !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($input['q']))).'%';
            $query->leftJoin('users as staff', 'staff.id', '=', 't.assigned_user_id')
                ->where(function ($search) use ($term, $input): void {
                    foreach (['r.number', 't.type', 't.status', 't.assigned_to', 'staff.name'] as $column) {
                        $search->orWhereRaw("LOWER($column) LIKE ? ESCAPE '!'", [$term]);
                    }
                    if (preg_match('/^[1-9][0-9]{0,17}$/D', trim($input['q']))) {
                        $search->orWhere('t.id', trim($input['q']));
                    }
                });
        }
        $scope = $this->scoped($hotel);
        if ($request->boolean('mine')) $scope->where('t.assigned_user_id', $request->user()->id);
        if (isset($input['assignee'])) $scope->where('t.assigned_user_id', $input['assignee']);
        $pastDueCount = (clone $scope)->where('t.status', '!=', 'done')->where('t.due_at', '<', $dueNow)->count();
        $pendingInspectionCount = $this->pendingInspection(clone $scope)->count();
        if (($input['sort'] ?? null) === 'priority') {
            $query->orderByRaw("CASE WHEN t.status = 'done' THEN 4 WHEN arrival.assigned_room_id IS NOT NULL AND t.type != 'stayover' THEN 0 WHEN t.due_at IS NOT NULL AND t.due_at < ? THEN 1 WHEN t.status = 'in_progress' THEN 2 ELSE 3 END", [$dueNow])
                ->orderByRaw('CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END')->orderBy('t.due_at');
        }
        $page=$query->orderByDesc('t.id')->paginate((int)($input['per_page']??20));
        $pendingIds = $this->pendingInspection($this->scoped($hotel))
            ->whereIn('t.id', $page->getCollection()->pluck('id')->all())->pluck('t.id')->map(fn ($id) => (string) $id)->all();
        return response()->json([
            'data'=>$page->getCollection()->map(fn (object $row): array=>[
                ...$this->present($row),
                'arrivalToday' => $row->status !== 'done' && $row->type !== 'stayover' && $row->arrival_room !== null,
                'overdue' => $row->status !== 'done' && $row->due_at !== null && (string) $row->due_at < $dueNow,
                'pendingInspection' => in_array((string) $row->id, $pendingIds, true),
            ])->values(),
            'meta'=>[
                'page'=>$page->currentPage(), 'perPage'=>$page->perPage(),
                'total'=>$page->total(), 'totalPages'=>$page->lastPage(),
                'determinesRoomReadiness'=>false, 'pastDueCount'=>$pastDueCount, 'pendingInspectionCount'=>$pendingInspectionCount,
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

    /** Claim only genuinely unassigned, not-started tasks. Never take another employee's work. */
    public function claim(Request $request, Hotel $hotel, string $task): JsonResponse
    {
        $user = $request->user();
        $member = $user->memberships()->where('hotel_id',$hotel->id)->where('role','housekeeping')
            ->where('is_active',true)->first();
        abort_unless($user->is_active && $member !== null
            && in_array('housekeeping.assigned',\App\Http\Middleware\EnsureHotelPermission::effectiveForMembership($member),true),403);
        $data = $request->validate(['expectedRevision'=>['required','integer','min:1']]);
        DB::transaction(function () use ($hotel,$task,$user,$data): void {
            app(\App\Domain\Reservations\ReadinessWriteGuard::class)->lockHotel($hotel);
            $row=DB::table('housekeeping_tasks')->where('hotel_id',$hotel->id)->where('id',$task)->lockForUpdate()->first();
            abort_if($row===null,404);
            abort_if((int)$row->revision !== (int)$data['expectedRevision']
                || $row->status !== 'todo' || $row->assigned_user_id !== null
                || trim((string)$row->assigned_to) !== '',409,'This task is no longer available to claim. Refresh the task.');
            $changes=['assigned_user_id'=>$user->id,'revision'=>(int)$row->revision+1,'updated_at'=>now()];
            $next=(object)array_merge((array)$row,$changes);
            DB::table('housekeeping_tasks')->where('hotel_id',$hotel->id)->where('id',$row->id)->update($changes);
            $this->event($hotel,(int)$row->id,(int)$user->id,'updated',$this->state($row),$this->state($next),'Housekeeper claimed unassigned task.');
        });
        return $this->show($hotel,$task);
    }

    public function update(Request $request, Hotel $hotel, string $task): JsonResponse
    {
        $data=$request->validate([
            'expectedRevision'=>['required','integer','min:1'],
            'checkedItems'=>['sometimes','array','max:12'],
            'checkedItems.*'=>['required','string','distinct','max:40'],
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
            $template = self::CHECKLISTS[$row->type] ?? [];
            $saved = isset($row->cleaning_checklist) ? json_decode($row->cleaning_checklist, true, 512, JSON_THROW_ON_ERROR) : [];
            if (array_key_exists('checkedItems', $data)) {
                if (array_diff($data['checkedItems'], array_keys($template)) !== []) {
                    throw ValidationException::withMessages(['checkedItems'=>['Select only items from this task checklist.']]);
                }
                $nextChecklist = [];
                foreach (array_keys($template) as $key) {
                    if (in_array($key, $data['checkedItems'], true)) {
                        $nextChecklist[$key] = $saved[$key] ?? [
                            'checkedBy'=>(string)$request->user()->id,
                            'checkedAt'=>now()->toIso8601String(),
                        ];
                    }
                }
                if ($nextChecklist !== $saved) {
                    if ($row->status === 'done') throw ValidationException::withMessages(['checkedItems'=>['Completed cleaning evidence cannot be edited.']]);
                    $changes['cleaning_checklist'] = json_encode($nextChecklist, JSON_THROW_ON_ERROR);
                }
                $saved = $nextChecklist;
            }
            $legacyAdminCompletion = false;
            if (($changes['status'] ?? null) === 'done' && $row->status !== 'done' && $template !== []) {
                // Preserve the pre-HK-04 manager status-only API during rollout;
                // never invent checklist evidence for that compatibility path.
                $legacyAdminCompletion = TaskStaffAccess::isAdmin($request, $hotel)
                    && !array_key_exists('checkedItems', $data) && $row->cleaning_checklist === null;
                if (!$legacyAdminCompletion && count($saved) !== count($template)) {
                    throw ValidationException::withMessages(['checkedItems'=>['Complete every required cleaning item before finishing.']]);
                }
            }
            // Compare normalized values to avoid a false edit caused by formatting nullable timestamps.
            $next=(object)array_merge((array)$row,$changes);
            $before=$this->state($row);
            $after=$this->state($next);
            $note=isset($data['note'])?trim($data['note']):null;
            if ($before===$after && ($note===null||$note==='')) return ['conflict'=>false];
            $changes['revision']=(int)$row->revision+1;
            $changes['updated_at']=now();
            if (($changes['status']??null)==='done' && $row->status!=='done') {
                $changes['completed_at']=now();
                $changes['completed_by']=$request->user()->id;
                if ($legacyAdminCompletion) $after['checklistEvidence']='legacy_status_only';
                $after['completedBy']=(string)$request->user()->id;
            }
            DB::table('housekeeping_tasks')->where('id',$row->id)->update($changes);
            $this->event($hotel,(int)$row->id,(int)$request->user()->id,'updated',$before,$after,$note);
            return ['conflict'=>false];
        });
        if ($result['conflict']) {
            return response()->json(['message'=>'This task was updated by another administrator. Reload before saving.'],409);
        }
        return $this->show($hotel,$task);
    }

    /**
     * Final inspection is stage two of the SAME housekeeping task.
     * The existing readiness service writes the canonical inspected-room evidence.
     */
    public function inspect(Request $request, Hotel $hotel, string $task): JsonResponse
    {
        $data = $request->validate([
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'cleaningVerified' => ['required', 'accepted'],
            'safetyVerified' => ['required', 'accepted'],
            'amenitiesVerified' => ['required', 'accepted'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $result = DB::transaction(function () use ($request, $hotel, $task, $data): array {
            app(\App\Domain\Reservations\ReadinessWriteGuard::class)->lockHotel($hotel);
            $row = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)
                ->where('id', $task)->lockForUpdate()->first();
            abort_if($row === null, 404);
            if (! TaskStaffAccess::canOperate($request, $hotel, $row, 'housekeeping')) abort(403);
            if ((int) $row->revision !== (int) $data['expectedRevision']) {
                return ['conflict' => true];
            }
            if ($row->status !== 'done') {
                throw ValidationException::withMessages([
                    'status' => ['Complete the cleaning stage before final room inspection.'],
                ]);
            }
            if ($row->type === 'stayover') {
                throw ValidationException::withMessages([
                    'status' => ['Stayover service does not release an occupied room for check-in.'],
                ]);
            }
            $service = app(\App\Domain\Reservations\RoomReadinessService::class);
            $prior = $service->show($hotel, (int) $row->room_id);
            if ($prior['advisoryReady']) {
                throw ValidationException::withMessages([
                    'status' => ['This room already has a current inspection. Refresh the task.'],
                ]);
            }
            $inspection = $service->inspect($hotel, (int) $row->room_id, (int) $request->user()->id, [
                'cleaningVerified' => true,
                'safetyVerified' => true,
                'amenitiesVerified' => true,
                'note' => trim($data['note']),
            ]);
            DB::table('housekeeping_tasks')->where('id', $row->id)->update([
                'revision' => (int) $row->revision + 1, 'updated_at' => now(),
            ]);
            $this->event($hotel, (int) $row->id, (int) $request->user()->id,
                'inspection_completed', $this->state($row), [
                    ...$this->state($row),
                    'inspectionId' => $inspection['inspection']['id'],
                    'inspectionStatus' => 'approved',
                ], trim($data['note']));
            return ['conflict' => false];
        }, 3);
        if ($result['conflict']) {
            return response()->json(['message' => 'The housekeeping task changed. Reload before inspection.'], 409);
        }
        return response()->json([
            'data' => $this->present($this->scoped($hotel)->where('t.id', $task)->first()),
        ]);
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

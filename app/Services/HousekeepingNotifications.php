<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

final class HousekeepingNotifications
{
    private const MANAGER_KINDS = ['hk_checkout_handoff','hk_arrival_urgent','hk_overdue','lost_found_registered'];

    private function tasks(Hotel $hotel)
    {
        return DB::table('housekeeping_tasks as t')->join('rooms as r',function ($join): void {
            $join->on('r.id','=','t.room_id')->on('r.hotel_id','=','t.hotel_id');
        })->where('t.hotel_id',$hotel->id)->whereIn('t.status',['todo','in_progress'])
            ->select('t.id','t.due_at','r.number as room_number');
    }

    private function managerQuery(Hotel $hotel, string $kind)
    {
        $clock=now($hotel->timezone ?: 'Asia/Manila');
        if ($kind === 'lost_found_registered') {
            return DB::table('lost_found_items as i')->join('rooms as r',function ($join): void {
                $join->on('r.id','=','i.room_id')->on('r.hotel_id','=','i.hotel_id');
            })->where('i.hotel_id',$hotel->id)->where('i.created_at','>=',now()->subDay())
                ->select('i.id','i.created_at','r.number as room_number')->orderByDesc('i.id');
        }
        $query=$this->tasks($hotel);
        if ($kind === 'hk_checkout_handoff') {
            $query->where('t.type','checkout_clean')->whereNull('t.assigned_user_id')
                ->whereExists(function ($link): void {
                    $link->selectRaw('1')->from('reservation_turnover_links as l')
                        ->whereColumn('l.hotel_id','t.hotel_id')->whereColumn('l.housekeeping_task_id','t.id');
                });
        } elseif ($kind === 'hk_arrival_urgent') {
            $query->where('t.type','!=','stayover')->whereExists(function ($arrival) use ($clock): void {
                $arrival->selectRaw('1')->from('reservation_records as b')->whereColumn('b.hotel_id','t.hotel_id')
                    ->whereColumn('b.assigned_room_id','t.room_id')->where('b.lifecycle_state','confirmed')
                    ->where('b.check_in',$clock->toDateString());
            });
        } elseif ($kind === 'hk_overdue') {
            $query->where('t.due_at','<',$clock->toDateTimeString());
        } else {
            return null;
        }
        return $query->orderByRaw('CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END')->orderBy('t.due_at')->orderBy('t.id');
    }

    private function managerItem(Hotel $hotel,string $kind,object $row): array
    {
        $today=now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $id=$kind.':'.$row->id.(in_array($kind,['hk_arrival_urgent','hk_overdue'],true)?':'.$today:'');
        $date=$kind==='lost_found_registered'?\Illuminate\Support\Carbon::parse($row->created_at)->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString():$today;
        return ['id'=>$id,'kind'=>$kind,'date'=>$date,'reference'=>'Room '.$row->room_number]
            + ($kind==='lost_found_registered'?['target'=>'lost-found','itemId'=>(string)$row->id]:['target'=>'housekeeping','taskId'=>(string)$row->id]);
    }

    public function manager(Hotel $hotel, ?int $limit=null): iterable
    {
        foreach (self::MANAGER_KINDS as $kind) {
            $query=$this->managerQuery($hotel,$kind);
            foreach ($limit===null?$query->lazy(200):$query->limit($limit)->get() as $row) {
                yield $this->managerItem($hotel,$kind,$row);
            }
        }
    }

    public function currentManager(Hotel $hotel,string $id): ?array
    {
        if (!preg_match('/^([a-z_]+):([1-9][0-9]*)(?::[0-9]{4}-[0-9]{2}-[0-9]{2})?$/D',$id,$parts)
            || !in_array($parts[1],self::MANAGER_KINDS,true)) return null;
        $row=$this->managerQuery($hotel,$parts[1])->where($parts[1]==='lost_found_registered'?'i.id':'t.id',$parts[2])->first();
        if (!$row) return null;
        $item=$this->managerItem($hotel,$parts[1],$row);
        return $item['id']===$id?$item:null;
    }

    /** Personal assignment alerts are never added to the hotel-wide push feed. */
    public function personal(Hotel $hotel,int $userId): array
    {
        $items=[];$hasMore=false;
        // Select the latest real assignment change, not ordinary progress or note updates.
        $events=DB::table('housekeeping_task_events as e')->join('housekeeping_tasks as t',function ($join): void {
            $join->on('t.id','=','e.task_id')->on('t.hotel_id','=','e.hotel_id');
        })->join('rooms as r',function ($join): void {
            $join->on('r.id','=','t.room_id')->on('r.hotel_id','=','t.hotel_id');
        })->where('e.hotel_id',$hotel->id)->where('t.assigned_user_id',$userId)
            ->whereIn('t.status',['todo','in_progress'])->where('e.created_at','>=',now()->subDay())
            ->whereIn('e.action',['created','updated'])->select('e.id','e.task_id','e.before_state','e.after_state','e.created_at','r.number as room_number')
            ->orderByDesc('e.id')->lazy(200);
        $seen=[];$count=0;
        foreach ($events as $event) {
            $before=json_decode($event->before_state??'null',true);
            $after=json_decode($event->after_state,true);
            $from=$before['assignedUserId']??null;$to=$after['assignedUserId']??null;
            if ($from===$to||isset($seen[$event->task_id])) continue;
            $seen[$event->task_id]=true;
            if ((string)$to!==(string)$userId) continue;
            if (++$count>8) {$hasMore=true;break;}
            $items[]=['id'=>'hk_assigned:'.$event->id,'kind'=>'hk_assigned','date'=>\Illuminate\Support\Carbon::parse($event->created_at)->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString(),
                'reference'=>'Room '.$event->room_number,'target'=>'housekeeping','taskId'=>(string)$event->task_id];
        }
        foreach (['hk_arrival_urgent','hk_overdue'] as $kind) {
            $rows=$this->managerQuery($hotel,$kind)->where('t.assigned_user_id',$userId)->limit(9)->get();
            $hasMore=$hasMore||$rows->count()>8;
            foreach ($rows->take(8) as $row) $items[]=$this->managerItem($hotel,$kind,$row);
        }
        return ['hotelId'=>(string)$hotel->id,'notifications'=>$items,'hasMore'=>$hasMore];
    }
}

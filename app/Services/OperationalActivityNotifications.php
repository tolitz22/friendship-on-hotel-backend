<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OperationalActivityNotifications
{
    public const KINDS = ['housekeeping_completed', 'room_inspection_approved', 'room_inspection_rejected', 'maintenance_created', 'maintenance_urgent', 'maintenance_resolved', 'room_blocked', 'room_unblocked', 'approval_requested', 'approval_approved', 'approval_rejected'];

    private function query(Hotel $hotel, string $kind): ?Builder
    {
        $table = match ($kind) {
            'housekeeping_completed' => 'housekeeping_tasks',
            'room_inspection_approved', 'room_inspection_rejected' => 'room_readiness_events',
            'maintenance_created', 'maintenance_urgent', 'maintenance_resolved' => 'maintenance_work_orders',
            'room_blocked', 'room_unblocked' => 'room_inventory_blocks',
            'approval_requested', 'approval_approved', 'approval_rejected' => 'manager_approval_events',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        $timestamp = match ($kind) {
            'housekeeping_completed' => 'completed_at',
            'maintenance_resolved' => 'resolved_at',
            'room_unblocked' => 'updated_at',
            default => 'created_at',
        };
        $query = DB::table($table.' as e')->where('e.hotel_id', $hotel->id)
            ->where('e.'.$timestamp, '>=', now()->subDay())
            ->select(['e.id', 'e.'.$timestamp.' as occurred_at']);
        match ($kind) {
            'housekeeping_completed' => $query->where('e.status', 'done'),
            'room_inspection_approved' => $query->where('e.action', 'approved'),
            'room_inspection_rejected' => $query->where('e.action', 'rejected'),
            'maintenance_created' => $query->where('e.priority', '!=', 'urgent'),
            'maintenance_urgent' => $query->where('e.priority', 'urgent')->whereIn('e.status', ['open', 'in_progress']),
            'maintenance_resolved' => $query->whereIn('e.status', ['resolved', 'closed']),
            'room_unblocked' => $query->where('e.is_active', false)->whereNotNull('e.released_by'),
            'approval_requested' => $query->where('e.event', 'requested'),
            'approval_approved' => $query->where('e.event', 'approved'),
            'approval_rejected' => $query->where('e.event', 'rejected'),
            default => null,
        };
        if (str_starts_with($kind, 'approval_')) {
            $query->join('manager_approval_requests as a', function ($join): void {
                $join->on('a.id', '=', 'e.approval_request_id')->on('a.hotel_id', '=', 'e.hotel_id');
            })->addSelect('a.id as approval_id');
            if ($kind === 'approval_requested') {
                $query->where('a.status', 'pending')->where('a.expires_at', '>', now());
            }
        } else {
            $query->join('rooms as r', function ($join): void {
                $join->on('r.id', '=', 'e.room_id')->on('r.hotel_id', '=', 'e.hotel_id');
            })->addSelect('r.number as room_number');
            if (in_array($kind, ['room_blocked', 'room_unblocked'], true)) {
                $query->addSelect('e.check_in as block_date');
            }
        }

        return $query->orderByDesc('e.'.$timestamp)->orderByDesc('e.id');
    }

    private function item(Hotel $hotel, string $kind, object $row): array
    {
        $item = [
            'id' => $kind.':'.$row->id,
            'kind' => $kind,
            'date' => Carbon::parse($row->occurred_at)->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString(),
        ];
        if (isset($row->approval_id)) {
            return $item + ['target' => 'manager-approvals', 'approvalId' => (string) $row->approval_id, 'reference' => 'Approval #'.$row->approval_id];
        }
        $item['reference'] = 'Room '.$row->room_number;
        if ($kind === 'housekeeping_completed') {
            return $item + ['target' => 'housekeeping', 'taskId' => (string) $row->id];
        }
        if (str_starts_with($kind, 'maintenance_')) {
            return $item + ['target' => 'maintenance', 'orderId' => (string) $row->id];
        }
        if (isset($row->block_date)) {
            return $item + ['target' => 'room-calendar', 'room' => (string) $row->room_number, 'calendarDate' => (string) $row->block_date];
        }

        return $item + ['target' => 'rooms', 'room' => (string) $row->room_number];
    }

    public function all(Hotel $hotel, ?int $limit = null): iterable
    {
        foreach (self::KINDS as $kind) {
            $query = $this->query($hotel, $kind);
            $rows = $limit === null ? $query->lazy(200) : $query->limit($limit)->get();
            foreach ($rows as $row) {
                yield $this->item($hotel, $kind, $row);
            }
        }
    }

    public function current(Hotel $hotel, string $id): ?array
    {
        if (! preg_match('/^([a-z_]+):([1-9][0-9]*)$/D', $id, $parts)) {
            return null;
        }
        $row = $this->query($hotel, $parts[1])?->where('e.id', $parts[2])->first();

        return $row ? $this->item($hotel, $parts[1], $row) : null;
    }
}

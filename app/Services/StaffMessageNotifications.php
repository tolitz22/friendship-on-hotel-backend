<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class StaffMessageNotifications
{
    public const KINDS = ['staff_message', 'staff_announcement', 'staff_handover', 'staff_acknowledged'];

    private function query(Hotel $hotel, int $userId, string $kind): ?Builder
    {
        if (! in_array($kind, self::KINDS, true)) {
            return null;
        }
        // Never expose a conversation via the hotel-wide notification iterator.
        $query = DB::table('staff_messages as m')
            ->join('staff_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.hotel_id', $hotel->id)
            ->whereExists(function ($q) use ($hotel, $userId): void {
                $q->selectRaw('1')->from('hotel_memberships as hm')->join('users as u', 'u.id', '=', 'hm.user_id')
                    ->where('hm.hotel_id', $hotel->id)->where('hm.user_id', $userId)
                    ->where('hm.is_active', true)->where('hm.role', 'admin')
                    ->where('u.is_active', true)->where('u.must_change_password', false);
            })->leftJoin('staff_conversation_members as cm', function ($join) use ($userId): void {
                $join->on('cm.conversation_id', '=', 'c.id')->where('cm.user_id', $userId);
            })->where(function ($q): void {
                $q->where(function ($private): void {
                    $private->whereIn('c.kind', ['direct', 'group'])->whereNotNull('cm.user_id');
                })->orWhere(function ($channel): void {
                    $channel->where('c.kind', 'channel')->whereIn('c.direct_key', ['channel:all', 'channel:admin', 'channel:front_desk', 'channel:housekeeping', 'channel:maintenance']);
                });
            })->select(['m.id', 'm.conversation_id']);

        if ($kind === 'staff_acknowledged') {
            return $query->join('staff_announcement_recipients as a', 'a.message_id', '=', 'm.id')
                ->where('m.sender_id', $userId)->where('a.user_id', '!=', $userId)
                ->where('a.acknowledged_at', '>=', now()->subDay())
                ->addSelect(['a.user_id as acknowledging_id', 'a.acknowledged_at as occurred_at'])
                ->orderByDesc('a.acknowledged_at')->orderByDesc('m.id')->orderByDesc('a.user_id');
        }

        $query->where('m.sender_id', '!=', $userId)->where('m.created_at', '>=', now()->subDay())
            ->addSelect('m.created_at as occurred_at');
        if ($kind === 'staff_message') {
            $query->where('m.is_announcement', false)->whereNull('m.handover')
                ->where(function ($q): void {
                    $q->whereNull('cm.last_read_message_id')->orWhereColumn('m.id', '>', 'cm.last_read_message_id');
                });
        } else {
            $query->join('staff_announcement_recipients as a', function ($join) use ($userId): void {
                $join->on('a.message_id', '=', 'm.id')->where('a.user_id', $userId);
            })->whereNull('a.acknowledged_at');
            if ($kind === 'staff_announcement') {
                $query->where('m.is_announcement', true);
            } else {
                $query->whereNotNull('m.handover');
            }
        }

        return $query->orderByDesc('m.created_at')->orderByDesc('m.id');
    }

    private function item(Hotel $hotel, string $kind, object $row): array
    {
        return [
            'id' => $kind.':'.$row->id.(isset($row->acknowledging_id) ? ':'.$row->acknowledging_id : ''),
            'kind' => $kind,
            'target' => 'messages',
            'conversationId' => (string) $row->conversation_id,
            'reference' => 'Staff conversation',
            'date' => Carbon::parse($row->occurred_at)->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString(),
        ];
    }

    public function all(Hotel $hotel, int $userId, ?int $limit = null): iterable
    {
        foreach (self::KINDS as $kind) {
            $query = $this->query($hotel, $userId, $kind);
            foreach ($limit === null ? $query->lazy(200) : $query->limit($limit)->get() as $row) {
                yield $this->item($hotel, $kind, $row);
            }
        }
    }

    public function current(Hotel $hotel, int $userId, string $id): ?array
    {
        if (! preg_match('/^([a-z_]+):([1-9][0-9]*)(?::([1-9][0-9]*))?$/D', $id, $parts)) {
            return null;
        }
        $query = $this->query($hotel, $userId, $parts[1]);
        if (! $query || ($parts[1] === 'staff_acknowledged') !== isset($parts[3])) {
            return null;
        }
        $query->where('m.id', $parts[2]);
        if (isset($parts[3])) {
            $query->where('a.user_id', $parts[3]);
        }
        $row = $query->first();

        return $row ? $this->item($hotel, $parts[1], $row) : null;
    }
}

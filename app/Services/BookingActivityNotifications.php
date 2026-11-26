<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

final class BookingActivityNotifications
{
    private const STATES = [
        'guest_check_in' => 'checked_in',
        'guest_check_out' => 'checked_out',
        'booking_cancelled' => 'cancelled',
        'booking_no_show' => 'no_show',
    ];

    private function query(Hotel $hotel, string $kind): ?\Illuminate\Database\Query\Builder
    {
        if ($kind === 'stay_extended') {
            $query = DB::table('reservation_date_amendments as e')
                ->whereColumn('e.new_check_out', '>', 'e.old_check_out');
        } elseif (isset(self::STATES[$kind])) {
            $query = DB::table('reservation_booking_events as e')
                ->where('e.new_state', self::STATES[$kind])
                ->whereNotNull('e.old_state')->whereColumn('e.old_state', '!=', 'e.new_state');
        } else {
            return null;
        }

        return $query->join('reservation_records as b', function ($join): void {
            $join->on('b.id', '=', 'e.reservation_record_id')->on('b.hotel_id', '=', 'e.hotel_id');
        })->where('e.hotel_id', $hotel->id)->where('e.created_at', '>=', now()->subDay())
            ->select(['e.id', 'e.reservation_record_id', 'e.created_at', 'b.reference'])
            ->orderByDesc('e.created_at')->orderByDesc('e.id');
    }

    private function item(Hotel $hotel, string $kind, object $row): array
    {
        return [
            'id' => $kind.':'.$row->id,
            'kind' => $kind,
            'bookingId' => (string) $row->reservation_record_id,
            'reference' => $row->reference,
            'date' => \Illuminate\Support\Carbon::parse($row->created_at)->setTimezone($hotel->timezone ?: 'Asia/Manila')->toDateString(),
        ];
    }

    public function all(Hotel $hotel, ?int $limit = null): iterable
    {
        foreach (array_merge(array_keys(self::STATES), ['stay_extended']) as $kind) {
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

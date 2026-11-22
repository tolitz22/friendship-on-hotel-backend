<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 36 lock protocol: acquire the hotel row before any subordinate
 * reservation/room/task/maintenance lock. Coarse per-property serialization
 * is intentional for opt-in room-readiness safety.
 *
 * Only call inside an existing DB transaction.
 */
final class ReadinessWriteGuard
{
    public function lockHotel(Hotel $hotel): void
    {
        $row = DB::table('hotels')->where('id', $hotel->id)
            ->lockForUpdate()->first(['id']);
        abort_unless($row !== null, 404);
    }

    public function enabled(Hotel $hotel): bool
    {
        return (bool) (DB::table('hotel_readiness_policies')
            ->where('hotel_id', $hotel->id)->value('enforce_at_check_in') ?? false);
    }

    public function requireReady(Hotel $hotel, int $roomId): void
    {
        $assessment = app(RoomReadinessService::class)->show($hotel, $roomId);
        if (! $assessment['advisoryReady']) {
            throw new HttpException(409, 'Complete a current physical room inspection and clear readiness blockers before check-in.');
        }
    }
}

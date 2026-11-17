<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

/**
 * Examines known physical room stays only.
 *
 * Does NOT determine hotel inventory availability: it lacks unassigned room-type
 * demand, statuses, manual blocks, maintenance policy, room holds and concurrency.
 */
final class RoomOverlapDetector
{
    /**
     * @param iterable<array{reference:string,room_id:string|int,arrival:string,departure:string}> $knownStays
     * @return list<string> References of stays overlapping the candidate period
     */
    public function conflictingReferences(string|int $roomId, StayPeriod $candidate, iterable $knownStays): array
    {
        $conflicts = [];

        foreach ($knownStays as $stay) {
            if ((string) $stay['room_id'] !== (string) $roomId) {
                continue;
            }

            if ($candidate->overlaps(new StayPeriod($stay['arrival'], $stay['departure']))) {
                $conflicts[] = $stay['reference'];
            }
        }

        return $conflicts;
    }
}

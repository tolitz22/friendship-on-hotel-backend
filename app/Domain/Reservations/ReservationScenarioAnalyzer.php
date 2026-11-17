<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

/**
 * An explanatory preview of stored overlaps, NOT a booking decision.
 * All lifecycle states participate, including cancelled records.
 */
final class ReservationScenarioAnalyzer
{
    /**
     * @param list<array{id:string,reference:string,roomTypeId:string,assignedRoomId:?string,checkIn:string,checkOut:string,lifecycleState:?string}> $records
     */
    public function analyze(StayPeriod $proposed, string $roomTypeId, string $roomId, array $records): array
    {
        $assigned = [];
        $unassigned = [];
        $otherAssignedSameType = 0;

        foreach ($records as $record) {
            $existing = new StayPeriod($record['checkIn'], $record['checkOut']);
            if (! $existing->overlaps($proposed)) {
                continue;
            }

            if ($record['assignedRoomId'] === $roomId) {
                $assigned[] = $record;
            } elseif ($record['roomTypeId'] === $roomTypeId && $record['assignedRoomId'] === null) {
                $unassigned[] = $record;
            } elseif ($record['roomTypeId'] === $roomTypeId) {
                $otherAssignedSameType++;
            }
        }

        return [
            'proposedRoomId' => $roomId,
            'roomTypeId' => $roomTypeId,
            'checkIn' => $proposed->arrival,
            'checkOut' => $proposed->departure,
            'nights' => $proposed->nights(),
            'assignedRoomOverlaps' => $assigned,
            'unassignedTypeOverlaps' => $unassigned,
            'otherAssignedSameTypeCount' => $otherAssignedSameType,
            'hasAssignedRoomOverlap' => count($assigned) > 0,
        ];
    }
}

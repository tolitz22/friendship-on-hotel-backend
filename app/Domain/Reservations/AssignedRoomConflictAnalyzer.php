<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

/**
 * Diagnostic comparison of known, assigned reservation records.
 * State is intentionally ignored because inventory-consuming statuses are not approved.
 */
final class AssignedRoomConflictAnalyzer
{
    /**
     * @param iterable<array{id:string,reference:string,arrival:string,departure:string}> $records
     * @return list<array{firstId:string,secondId:string,firstReference:string,secondReference:string}>
     */
    public function pairs(iterable $records): array
    {
        $previous = [];
        $pairs = [];

        foreach ($records as $record) {
            $period = new StayPeriod($record['arrival'], $record['departure']);

            foreach ($previous as $item) {
                if (! $period->overlaps($item['period'])) {
                    continue;
                }

                $pairs[] = [
                    'firstId' => $item['id'],
                    'secondId' => $record['id'],
                    'firstReference' => $item['reference'],
                    'secondReference' => $record['reference'],
                ];
            }

            $previous[] = [
                'id' => $record['id'],
                'reference' => $record['reference'],
                'period' => $period,
            ];
        }

        return $pairs;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Read-only, hypothetical nightly demand under a caller-supplied lifecycle plan.
 * Does not compute bookable capacity, authorize a hold, or create a reservation.
 */
final class ReservationNightDemandProjection
{
    /**
     * @param list<array{id:string,roomTypeId:string,assignedRoomId:?string,checkIn:string,checkOut:string,lifecycleState:?string}> $records
     * @return array<string, mixed>
     */
    public function project(
        StayPeriod $period,
        string $roomTypeId,
        array $records,
        ReservationLifecycleBlueprint $blueprint
    ): array {
        if ($period->nights() > 31) {
            throw new InvalidArgumentException('Demand previews are limited to 31 nights.');
        }
        if ($roomTypeId === '') {
            throw new InvalidArgumentException('A room type must be selected.');
        }

        $nights = [];
        $cursor = new DateTimeImmutable($period->arrival);
        $end = new DateTimeImmutable($period->departure);

        while ($cursor < $end) {
            $date = $cursor->format('Y-m-d');
            $nights[$date] = [
                'date' => $date,
                'assignedRoomRecordIds' => [],
                'unassignedRoomTypeRecordIds' => [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($records as $record) {
            if ($record['roomTypeId'] !== $roomTypeId
                || $record['lifecycleState'] === null
                || ! $blueprint->describesInventoryConsumption($record['lifecycleState'])) {
                continue;
            }

            $stay = new StayPeriod($record['checkIn'], $record['checkOut']);
            if (! $stay->overlaps($period)) {
                continue;
            }

            foreach ($nights as $date => &$night) {
                if (! $stay->containsNight($date)) {
                    continue;
                }
                $field = $record['assignedRoomId'] === null
                    ? 'unassignedRoomTypeRecordIds'
                    : 'assignedRoomRecordIds';
                $night[$field][] = $record['id'];
            }
            unset($night);
        }

        return [
            'roomTypeId' => $roomTypeId,
            'checkIn' => $period->arrival,
            'checkOut' => $period->departure,
            'assumedInventoryConsumingStates' => $blueprint->inventoryConsumingStates,
            'nights' => array_values($nights),
            'diagnosticOnly' => true,
            'isAvailability' => false,
            'canBook' => false,
        ];
    }
}

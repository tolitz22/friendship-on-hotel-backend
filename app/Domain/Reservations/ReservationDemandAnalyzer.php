<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use DateTimeImmutable;

/**
 * Counts provisional reservation records, WITHOUT deciding which states consume inventory.
 * These counts are NOT sellable capacity, occupancy, or availability.
 */
final class ReservationDemandAnalyzer
{
    /**
     * @param list<array{roomTypeId:string,assignedRoomId:?string,checkIn:string,checkOut:string}> $records
     * @param list<array{id:string,name:string,registeredRooms:int}> $roomTypes
     * @return list<array{date:string,roomTypes:list<array{roomTypeId:string,roomTypeName:string,registeredRooms:int,totalRecords:int,assignedRecords:int,unassignedRecords:int}>}>
     */
    public function analyze(StayPeriod $window, array $records, array $roomTypes): array
    {
        $types = [];
        foreach ($roomTypes as $type) {
            $types[$type['id']] = $type;
        }

        $dates = [];
        for ($day = new DateTimeImmutable($window->arrival); $day->format('Y-m-d') < $window->departure; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $dates[$date] = [];
            foreach ($types as $id => $type) {
                $dates[$date][$id] = [
                    'roomTypeId' => $id,
                    'roomTypeName' => $type['name'],
                    'registeredRooms' => $type['registeredRooms'],
                    'totalRecords' => 0,
                    'assignedRecords' => 0,
                    'unassignedRecords' => 0,
                ];
            }
        }

        foreach ($records as $record) {
            $typeId = $record['roomTypeId'];
            if (! isset($types[$typeId])) {
                continue;
            }

            $stay = new StayPeriod($record['checkIn'], $record['checkOut']);
            if (! $stay->overlaps($window)) {
                continue;
            }

            $cursor = new DateTimeImmutable(max($window->arrival, $stay->arrival));
            $end = min($window->departure, $stay->departure);
            for (; $cursor->format('Y-m-d') < $end; $cursor = $cursor->modify('+1 day')) {
                $date = $cursor->format('Y-m-d');
                $dates[$date][$typeId]['totalRecords']++;
                if ($record['assignedRoomId'] === null) {
                    $dates[$date][$typeId]['unassignedRecords']++;
                } else {
                    $dates[$date][$typeId]['assignedRecords']++;
                }
            }
        }

        $result = [];
        foreach ($dates as $date => $counts) {
            $result[] = ['date' => $date, 'roomTypes' => array_values($counts)];
        }
        return $result;
    }
}

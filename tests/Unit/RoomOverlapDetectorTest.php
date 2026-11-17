<?php

declare(strict_types=1);

use App\Domain\Reservations\RoomOverlapDetector;
use App\Domain\Reservations\StayPeriod;

it('detects physical room overlaps and excludes other rooms', function (): void {
    $known = [
        ['reference'=>'R1', 'room_id'=>'101', 'arrival'=>'2026-11-17', 'departure'=>'2026-11-20'],
        ['reference'=>'R2', 'room_id'=>'102', 'arrival'=>'2026-11-19', 'departure'=>'2026-11-22'],
        ['reference'=>'R3', 'room_id'=>'101', 'arrival'=>'2026-11-23', 'departure'=>'2026-11-25'],
    ];

    $conflicts = (new RoomOverlapDetector())->conflictingReferences(
        '101',
        new StayPeriod('2026-11-19', '2026-11-23'),
        $known,
    );

    expect($conflicts)->toBe(['R1']);
});

it('does not count checkout on the same date as a new checkin', function (): void {
    $known = [
        ['reference'=>'R1','room_id'=>'101','arrival'=>'2026-11-17','departure'=>'2026-11-20'],
    ];

    expect((new RoomOverlapDetector())->conflictingReferences(
        '101', new StayPeriod('2026-11-20', '2026-11-21'), $known,
    ))->toBe([]);
});

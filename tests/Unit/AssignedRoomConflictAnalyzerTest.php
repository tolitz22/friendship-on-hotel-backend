<?php

declare(strict_types=1);

use App\Domain\Reservations\AssignedRoomConflictAnalyzer;

it('reports only actual intersections between assigned records', function (): void {
    $records = [
        ['id'=>'1','reference'=>'A','arrival'=>'2026-11-17','departure'=>'2026-11-20'],
        ['id'=>'2','reference'=>'B','arrival'=>'2026-11-20','departure'=>'2026-11-22'],
        ['id'=>'3','reference'=>'C','arrival'=>'2026-11-19','departure'=>'2026-11-21'],
    ];

    expect((new AssignedRoomConflictAnalyzer())->pairs($records))->toEqual([
        ['firstId'=>'1','secondId'=>'3','firstReference'=>'A','secondReference'=>'C'],
        ['firstId'=>'2','secondId'=>'3','firstReference'=>'B','secondReference'=>'C'],
    ]);
});

it('does not flag successive same-day checkouts and checkins', function (): void {
    $records = [
        ['id'=>'1','reference'=>'A','arrival'=>'2026-11-17','departure'=>'2026-11-20'],
        ['id'=>'2','reference'=>'B','arrival'=>'2026-11-20','departure'=>'2026-11-21'],
    ];

    expect((new AssignedRoomConflictAnalyzer())->pairs($records))->toBe([]);
});

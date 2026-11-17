<?php

declare(strict_types=1);

use App\Domain\Reservations\ReservationLifecycleBlueprint;
use App\Domain\Reservations\ReservationNightDemandProjection;
use App\Domain\Reservations\StayPeriod;

function exampleLifecycleBlueprint(): ReservationLifecycleBlueprint
{
    // Hypothetical test fixture, not a hotel-approved booking policy.
    return new ReservationLifecycleBlueprint(
        ['pending', 'confirmed', 'checked_in', 'cancelled'],
        [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['checked_in', 'cancelled'],
        ],
        ['confirmed', 'checked_in'],
    );
}

it('requires explicit, internally consistent lifecycle choices', function (): void {
    expect(fn () => new ReservationLifecycleBlueprint([], [], []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new ReservationLifecycleBlueprint(['pending', 'pending'], [], []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new ReservationLifecycleBlueprint(['pending'], ['pending' => ['unknown']], []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new ReservationLifecycleBlueprint(['pending'], [], ['confirmed']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new ReservationLifecycleBlueprint(['pending'], ['pending' => ['pending']], []))
        ->toThrow(InvalidArgumentException::class);
});

it('describes only explicitly proposed transitions and remains inactive', function (): void {
    $plan = exampleLifecycleBlueprint();
    expect($plan->describesTransition('pending', 'confirmed'))->toBeTrue();
    expect($plan->describesTransition('confirmed', 'pending'))->toBeFalse();
    expect($plan->describesTransition('cancelled', 'confirmed'))->toBeFalse();
    expect($plan->describesInventoryConsumption('pending'))->toBeFalse();
    expect($plan->describesInventoryConsumption('confirmed'))->toBeTrue();
    expect($plan->diagnostic()['approved'])->toBeFalse();
    expect($plan->diagnostic()['effectiveForBookings'])->toBeFalse();
});

it('projects half-open nightly demand without making an availability claim', function (): void {
    $records = [
        [
            'id' => 'a', 'roomTypeId' => '1', 'assignedRoomId' => '10',
            'checkIn' => '2026-11-18', 'checkOut' => '2026-11-20',
            'lifecycleState' => 'confirmed',
        ],
        [
            'id' => 'b', 'roomTypeId' => '1', 'assignedRoomId' => null,
            'checkIn' => '2026-11-19', 'checkOut' => '2026-11-22',
            'lifecycleState' => 'checked_in',
        ],
        [
            'id' => 'c', 'roomTypeId' => '1', 'assignedRoomId' => '11',
            'checkIn' => '2026-11-19', 'checkOut' => '2026-11-21',
            'lifecycleState' => 'pending',
        ],
        [
            'id' => 'd', 'roomTypeId' => '2', 'assignedRoomId' => '12',
            'checkIn' => '2026-11-19', 'checkOut' => '2026-11-21',
            'lifecycleState' => 'confirmed',
        ],
    ];

    $result = (new ReservationNightDemandProjection())->project(
        new StayPeriod('2026-11-19', '2026-11-22'), '1', $records,
        exampleLifecycleBlueprint()
    );

    expect(array_column($result['nights'], 'date'))
        ->toBe(['2026-11-19', '2026-11-20', '2026-11-21']);
    expect($result['nights'][0]['assignedRoomRecordIds'])->toBe(['a']);
    expect($result['nights'][0]['unassignedRoomTypeRecordIds'])->toBe(['b']);
    expect($result['nights'][1]['assignedRoomRecordIds'])->toBe([]);
    expect($result['nights'][2]['unassignedRoomTypeRecordIds'])->toBe(['b']);
    expect($result['diagnosticOnly'])->toBeTrue();
    expect($result['isAvailability'])->toBeFalse();
    expect($result['canBook'])->toBeFalse();
});

it('rejects demand previews longer than 31 hotel-local nights', function (): void {
    expect(fn () => (new ReservationNightDemandProjection())->project(
        new StayPeriod('2026-11-01', '2026-12-03'), '1', [],
        exampleLifecycleBlueprint()
    ))->toThrow(InvalidArgumentException::class);
});

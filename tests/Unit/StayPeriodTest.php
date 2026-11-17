<?php

declare(strict_types=1);

use App\Domain\Reservations\StayPeriod;

it('counts occupied nights with exclusive departure', function (): void {
    $period = new StayPeriod('2026-11-17', '2026-11-20');

    expect($period->nights())->toBe(3)
        ->and($period->containsNight('2026-11-17'))->toBeTrue()
        ->and($period->containsNight('2026-11-19'))->toBeTrue()
        ->and($period->containsNight('2026-11-20'))->toBeFalse();
});

it('allows back-to-back stays without treating them as overlapping', function (): void {
    $first = new StayPeriod('2026-11-17', '2026-11-20');
    $second = new StayPeriod('2026-11-20', '2026-11-22');

    expect($first->overlaps($second))->toBeFalse()
        ->and($second->overlaps($first))->toBeFalse();
});

it('recognizes an overlap even when one stay contains another', function (): void {
    $outer = new StayPeriod('2026-11-17', '2026-11-25');
    $inner = new StayPeriod('2026-11-21', '2026-11-23');

    expect($outer->overlaps($inner))->toBeTrue()
        ->and($inner->overlaps($outer))->toBeTrue();
});

it('handles month and year boundaries', function (): void {
    expect((new StayPeriod('2026-12-31', '2027-01-02'))->nights())->toBe(2);
});

it('rejects invalid and inverted date ranges', function (): void {
    expect(fn () => new StayPeriod('2026-02-30', '2026-03-02'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new StayPeriod('2026-11-20', '2026-11-20'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new StayPeriod('2026-11-21', '2026-11-20'))->toThrow(InvalidArgumentException::class);
});

<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Date-only hotel-local stay, modelled as a half-open interval [arrival, departure).
 * Does not claim that inventory is bookable, held or sold.
 */
final readonly class StayPeriod
{
    public function __construct(public string $arrival, public string $departure)
    {
        self::assertDate($arrival);
        self::assertDate($departure);

        if ($departure <= $arrival) {
            throw new InvalidArgumentException('Departure must be later than arrival.');
        }
    }

    public function overlaps(self $other): bool
    {
        return $this->arrival < $other->departure
            && $this->departure > $other->arrival;
    }

    public function containsNight(string $date): bool
    {
        self::assertDate($date);

        return $this->arrival <= $date && $date < $this->departure;
    }

    public function nights(): int
    {
        $arrival = DateTimeImmutable::createFromFormat('!Y-m-d', $this->arrival);
        $departure = DateTimeImmutable::createFromFormat('!Y-m-d', $this->departure);

        return (int) $arrival->diff($departure)->days;
    }

    private static function assertDate(string $value): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $parsed || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Expected a valid YYYY-MM-DD hotel-local date.');
        }
    }
}

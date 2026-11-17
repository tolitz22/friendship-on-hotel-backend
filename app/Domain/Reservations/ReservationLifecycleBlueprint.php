<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use InvalidArgumentException;

/**
 * A hypothetical lifecycle specification, NOT an approved booking policy.
 *
 * No default states or transitions are supplied. Only explicitly passed
 * decisions can be evaluated; this object never performs a reservation write.
 */
final readonly class ReservationLifecycleBlueprint
{
    /** @var list<string> */
    public array $states;

    /** @var array<string, list<string>> */
    public array $transitions;

    /** @var list<string> */
    public array $inventoryConsumingStates;

    /**
     * @param list<string> $states
     * @param array<string, list<string>> $transitions
     * @param list<string> $inventoryConsumingStates
     */
    public function __construct(array $states, array $transitions, array $inventoryConsumingStates)
    {
        if ($states === [] || count($states) !== count(array_unique($states))) {
            throw new InvalidArgumentException('Define a nonempty set of unique lifecycle states.');
        }

        foreach ($states as $state) {
            if (! is_string($state) || trim($state) === '') {
                throw new InvalidArgumentException('Lifecycle states must be nonempty strings.');
            }
        }

        foreach ($transitions as $from => $targets) {
            if (! in_array($from, $states, true) || ! is_array($targets)) {
                throw new InvalidArgumentException('Transition sources must be declared states.');
            }
            if (count($targets) !== count(array_unique($targets))) {
                throw new InvalidArgumentException('Duplicate transition destinations are not allowed.');
            }
            foreach ($targets as $to) {
                if (! in_array($to, $states, true) || $to === $from) {
                    throw new InvalidArgumentException('Transitions must target a different declared state.');
                }
            }
        }

        if (count($inventoryConsumingStates) !== count(array_unique($inventoryConsumingStates))) {
            throw new InvalidArgumentException('Duplicate inventory-consuming states are not allowed.');
        }
        foreach ($inventoryConsumingStates as $state) {
            if (! in_array($state, $states, true)) {
                throw new InvalidArgumentException('Inventory-consuming states must be declared.');
            }
        }

        $this->states = $states;
        $this->transitions = $transitions;
        $this->inventoryConsumingStates = $inventoryConsumingStates;
    }

    public function describesTransition(string $from, string $to): bool
    {
        return in_array($to, $this->transitions[$from] ?? [], true);
    }

    public function describesInventoryConsumption(string $state): bool
    {
        return in_array($state, $this->inventoryConsumingStates, true);
    }

    public function diagnostic(): array
    {
        return [
            'states' => $this->states,
            'transitions' => $this->transitions,
            'inventoryConsumingStates' => $this->inventoryConsumingStates,
            'approved' => false,
            'effectiveForBookings' => false,
        ];
    }
}

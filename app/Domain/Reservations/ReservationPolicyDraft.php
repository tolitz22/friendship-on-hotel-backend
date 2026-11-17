<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

final class ReservationPolicyDraft
{
    public const FIELDS = [
        'sellableUnit' => ['physical_room', 'room_type', 'hybrid'],
        'assignmentTiming' => ['at_booking', 'before_arrival', 'at_check_in'],
        'inventoryConsumption' => ['confirmed_only', 'pending_and_confirmed', 'confirmed_and_checked_in', 'pending_confirmed_checked_in'],
        'holdPolicy' => ['no_pending_holds', 'timed_pending_holds'],
        'maintenanceBlocks' => ['block_out_of_service', 'block_out_of_service_and_manual'],
        'overbooking' => ['prohibited', 'authorized_override'],
        'cancellationPolicy' => ['manual_review', 'cutoff_based'],
        'ratePolicy' => ['manual_quote_review', 'published_rate_plans'],
    ];

    public static function empty(): array
    {
        return array_fill_keys(array_keys(self::FIELDS), null);
    }

    public static function completion(array $decisions): array
    {
        $missing = array_values(array_filter(array_keys(self::FIELDS), fn (string $field): bool =>
            ! isset($decisions[$field])
        ));
        return ['complete' => $missing === [], 'missing' => $missing];
    }
}

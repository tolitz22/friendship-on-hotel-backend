<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

/**
 * Pre-implementation review only. Never used to authorize bookings or inventory.
 * A complete choice list does not amount to an approved operational specification.
 */
final class ReservationPolicyReadiness
{
    public function assess(array $decisions): array
    {
        $choices = array_replace(ReservationPolicyDraft::empty(), $decisions);
        $issues = [];
        foreach (ReservationPolicyDraft::FIELDS as $field => $_) {
            if (! isset($choices[$field]) || ! in_array($choices[$field], ReservationPolicyDraft::FIELDS[$field], true)) {
                $issues[] = $this->issue('missing_'.$field, 'decision', $field, 'Select an allowed value for '.$field.'.');
            }
        }

        if (($choices['sellableUnit'] ?? null) === 'physical_room'
            && ($choices['assignmentTiming'] ?? null) !== null
            && $choices['assignmentTiming'] !== 'at_booking') {
            $issues[] = $this->issue(
                'physical_room_assignment_conflict', 'compatibility', 'assignmentTiming',
                'Selling a specific physical room while assigning it later requires an explicit inventory and guest-promise design.'
            );
        }

        if (($choices['holdPolicy'] ?? null) === 'timed_pending_holds') {
            $issues[] = $this->issue('hold_expiry_unspecified', 'operational_detail', 'holdPolicy',
                'Define the hold duration, expiry clock, release jobs, retry behavior and payment boundary.');
        }
        if (($choices['overbooking'] ?? null) === 'authorized_override') {
            $issues[] = $this->issue('overbooking_override_unspecified', 'operational_detail', 'overbooking',
                'Define maximum overbooking, eligible roles, audit reason and conflict resolution.');
        }
        if (($choices['cancellationPolicy'] ?? null) === 'cutoff_based') {
            $issues[] = $this->issue('cancellation_cutoff_unspecified', 'operational_detail', 'cancellationPolicy',
                'Define hotel-local cutoff time, refunds, fees, amendment behavior and inventory release.');
        }
        if (($choices['ratePolicy'] ?? null) === 'published_rate_plans') {
            $issues[] = $this->issue('rate_calculation_unspecified', 'operational_detail', 'ratePolicy',
                'Define rate plans, dates, taxes, deposits, rounding and price snapshots.');
        }

        $requiredSpecs = [
            'status_transitions' => 'Approve every reservation transition and precisely which states consume inventory.',
            'inventory_locking' => 'Design idempotent transactional allocation and last-room concurrency tests for MySQL and PostgreSQL.',
            'maintenance_inventory' => 'Define maintenance/manual room-night blocks and precedence over reservations.',
            'guest_and_pricing' => 'Specify primary guest linkage, charges, deposits and financial lifecycle.',
            'local_time_and_audit' => 'Specify timezone cutoffs, night audit, cancellation/amendment authority and write audit.',
        ];
        foreach ($requiredSpecs as $code => $message) {
            $issues[] = $this->issue($code, 'operational_specification', null, $message);
        }

        $completion = ReservationPolicyDraft::completion($choices);

        return [
            'configurationComplete' => $completion['complete'],
            'missingFields' => $completion['missing'],
            'readyForImplementation' => false,
            'readyForBookings' => false,
            'approved' => false,
            'issues' => $issues,
            'explanation' => 'Policy draft choices are not an approved, executable booking specification.',
        ];
    }

    private function issue(string $code, string $category, ?string $field, string $message): array
    {
        return compact('code', 'category', 'field', 'message');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Draft-only hotel charge policies. No legal or statutory rate is presumed.
 * Outputs are hypothetical and never update folios or bookings.
 */
final class ChargePolicyPreviewService
{
    public function createDraft(Hotel $hotel, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $input): array {
            DB::table('hotels')->where('id', $hotel->id)->lockForUpdate()->firstOrFail();
            $version = (int) DB::table('hotel_charge_policy_versions')
                ->where('hotel_id', $hotel->id)->max('version') + 1;
            $id = DB::table('hotel_charge_policy_versions')->insertGetId([
                'hotel_id' => $hotel->id,
                'version' => $version,
                'tax_basis_points' => $input['taxBasisPoints'],
                'service_basis_points' => $input['serviceBasisPoints'],
                'tax_label' => trim($input['taxLabel']),
                'service_label' => trim($input['serviceLabel']),
                'status' => 'draft',
                'reason' => trim($input['reason']),
                'created_by' => $actor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return ['id' => (string) $id, 'version' => $version, 'status' => 'draft',
                'effectiveForBookings' => false];
        }, 3);
    }

    /**
     * Two-person administrative review only; activation remains unavailable
     * until jurisdiction-specific legal validation and folio snapshot rules exist.
     */
    public function reviewDraft(Hotel $hotel, int $actor, int $policyId, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $policyId, $input): array {
            DB::table('hotels')->where('id', $hotel->id)->lockForUpdate()->firstOrFail();
            $policy = DB::table('hotel_charge_policy_versions')
                ->where('hotel_id', $hotel->id)->where('id', $policyId)
                ->lockForUpdate()->first();
            abort_unless($policy !== null, 404);
            if ($policy->status !== 'draft') {
                throw new HttpException(409, 'This policy version has already been reviewed.');
            }
            if ((int) $policy->created_by === $actor && $input['decision'] === 'approve') {
                throw new HttpException(403, 'Independent administrator approval is required.');
            }
            $status = $input['decision'] === 'approve' ? 'reviewed' : 'rejected';
            DB::table('hotel_charge_policy_versions')->where('id', $policyId)
                ->update(['status' => $status,
                    'reviewed_by' => $actor, 'review_note' => trim($input['note']),
                    'updated_at' => now()]);
            return ['id' => (string) $policyId, 'status' => $status,
                'effectiveForBookings' => false, 'legallyValidated' => false];
        }, 3);
    }

    public function policies(Hotel $hotel): array
    {
        return DB::table('hotel_charge_policy_versions')->where('hotel_id', $hotel->id)
            ->orderByDesc('version')->get()->map(fn (object $row): array => [
                'id' => (string) $row->id, 'version' => (int) $row->version,
                'taxBasisPoints' => (int) $row->tax_basis_points,
                'serviceBasisPoints' => (int) $row->service_basis_points,
                'taxLabel' => $row->tax_label, 'serviceLabel' => $row->service_label,
                'reason' => $row->reason, 'status' => $row->status,
                'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
                'reviewNote' => $row->review_note,
                'effectiveForBookings' => false,
            ])->values()->all();
    }

    public function preview(Hotel $hotel, int $bookingId, int $policyId): array
    {
        $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId);
        $policy = DB::table('hotel_charge_policy_versions')->where('hotel_id', $hotel->id)
            ->where('id', $policyId)->first();
        abort_unless($policy !== null, 404);

        $summary = app(FolioAccountingService::class)->summaryFor($folio);
        $base = $summary['roomSubtotalMinor'];
        $tax = $this->roundedBasisPoints($base, (int) $policy->tax_basis_points);
        $service = $this->roundedBasisPoints($base, (int) $policy->service_basis_points);

        if ($base > PHP_INT_MAX - $tax || $base + $tax > PHP_INT_MAX - $service) {
            throw new HttpException(422, 'Preview exceeds supported accounting range.');
        }
        return [
            'folioId' => (string) $folio->id,
            'policyVersion' => (int) $policy->version,
            'currency' => $folio->currency,
            'roomSubtotalMinor' => $base,
            'hypotheticalTaxMinor' => $tax,
            'hypotheticalServiceMinor' => $service,
            'hypotheticalRoomPlusChargesMinor' => $base + $tax + $service,
            'taxLabel' => $policy->tax_label,
            'serviceLabel' => $policy->service_label,
            'status' => 'draft_preview',
            'appliedToFolio' => false,
            'legallyValidated' => false,
            'taxInclusiveRatesSupported' => false,
            'isFinalPayableTotal' => false,
        ];
    }

    private function roundedBasisPoints(int $amount, int $basisPoints): int
    {
        // Half-up division by 10,000 without floating point, with overflow guard.
        if ($basisPoints < 0 || $basisPoints > 10000 || $amount < 0 ||
            $amount > intdiv(PHP_INT_MAX - 5000, max(1, $basisPoints))) {
            throw new HttpException(422, 'Charge calculation exceeds supported bounds.');
        }
        return intdiv($amount * $basisPoints + 5000, 10000);
    }
}

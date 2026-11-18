<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/**
 * Phase 55: manager-only, date-scoped coverage derived from independent
 * Phase 53 observations. Missing checks are unverified, not vacant.
 * Never retroactively recompute an observation from mutable PMS bookings.
 */
final class OccupancyCoverageReportService
{
    public function show(Hotel $hotel, array $filters): array
    {
        $date = $filters['date'] ?? now($hotel->timezone ?: 'Asia/Manila')->toDateString();
        $scope = DB::table('rooms as r')
            ->leftJoin('occupancy_verifications as v', function ($join) use ($hotel, $date): void {
                $join->on('v.room_id', '=', 'r.id')
                    ->on('v.hotel_id', '=', 'r.hotel_id')
                    ->where('v.hotel_id', '=', $hotel->id)
                    ->where('v.inspection_date', '=', $date);
            })
            ->leftJoin('occupancy_investigations as investigation', function ($join): void {
                $join->on('investigation.verification_id', '=', 'v.id')
                    ->on('investigation.hotel_id', '=', 'v.hotel_id');
            })
            ->leftJoin('users as assigned_employee','assigned_employee.id','=','v.assigned_user_id')
            ->leftJoin('users as observer_employee','observer_employee.id','=','v.observed_by')
            ->leftJoin('users as reviewer_employee','reviewer_employee.id','=','v.reviewed_by')
            ->where('r.hotel_id', $hotel->id);

        $totals = (clone $scope)->selectRaw(
            "COUNT(r.id) as room_count,
             SUM(CASE WHEN v.id IS NULL THEN 1 ELSE 0 END) as missing_count,
             SUM(CASE WHEN v.status = 'assigned' THEN 1 ELSE 0 END) as assigned_count,
             SUM(CASE WHEN v.status = 'submitted' THEN 1 ELSE 0 END) as submitted_count,
             SUM(CASE WHEN v.status = 'verified' THEN 1 ELSE 0 END) as verified_count,
             SUM(CASE WHEN v.status = 'flagged' THEN 1 ELSE 0 END) as flagged_count,
             SUM(CASE WHEN v.status = 'flagged' AND investigation.status = 'closed' THEN 1 ELSE 0 END) as resolved_count,
             SUM(CASE WHEN v.discrepancy = 'unexpected_occupied' THEN 1 ELSE 0 END) as unexpected_occupied_count,
             SUM(CASE WHEN v.discrepancy = 'expected_occupied_vacant' THEN 1 ELSE 0 END) as expected_occupied_vacant_count,
             SUM(CASE WHEN v.discrepancy = 'unverified' THEN 1 ELSE 0 END) as unverified_count,
             SUM(CASE WHEN v.discrepancy = 'match' THEN 1 ELSE 0 END) as match_count"
        )->first();

        $filter = $filters['filter'] ?? 'all';
        $records = clone $scope;
        if ($filter === 'missing') $records->whereNull('v.id');
        if ($filter === 'assigned') $records->where('v.status', 'assigned');
        if ($filter === 'needs_review') $records->where('v.status', 'submitted');
        if ($filter === 'flagged') $records->where('v.status', 'flagged')->where(function ($query): void {
            $query->whereNull('investigation.id')->orWhere('investigation.status', 'open');
        });
        if ($filter === 'mismatch') $records->whereIn('v.discrepancy', [
            'unexpected_occupied', 'expected_occupied_vacant', 'unverified',
        ]);
        if ($filter === 'verified') $records->where('v.status', 'verified');

        $page = $records->orderBy('r.number')->orderBy('r.id')
            ->select([
                'r.id as room_id', 'r.number as room_number', 'r.floor_number',
                'v.id as verification_id', 'v.status', 'v.discrepancy',
                'v.expected_occupancy', 'v.observed_occupancy',
                'v.assigned_user_id', 'v.observed_by', 'v.reviewed_by',
                'v.observed_at', 'v.reviewed_at',
                'investigation.status as investigation_status',
                'investigation.resolution as investigation_resolution',
                'assigned_employee.name as assigned_name',
                'observer_employee.name as observer_name',
                'reviewer_employee.name as reviewer_name',
            ])->paginate((int) ($filters['per_page'] ?? 25));

        $roomCount = (int) $totals->room_count;
        $missing = (int) $totals->missing_count;
        $assigned = (int) $totals->assigned_count;
        $submitted = (int) $totals->submitted_count;
        $verified = (int) $totals->verified_count;
        $flagged = (int) $totals->flagged_count;

        return [
            'data' => $page->getCollection()->map(fn (object $row): array => [
                'roomId' => (string) $row->room_id,
                'roomNumber' => $row->room_number,
                'floor' => $row->floor_number === null ? null : (int) $row->floor_number,
                'verificationId' => $row->verification_id === null ? null : (string) $row->verification_id,
                'status' => $row->status ?? 'missing',
                'investigationStatus' => $row->investigation_status,
                'investigationResolution' => $row->investigation_resolution,
                'discrepancy' => $row->discrepancy,
                'expectedOccupancy' => $row->expected_occupancy,
                'observedOccupancy' => $row->observed_occupancy,
                'assignedUserId' => $row->assigned_user_id === null ? null : (string) $row->assigned_user_id,
                'assignedName' => $row->assigned_name,
                'observedBy' => $row->observed_by === null ? null : (string) $row->observed_by,
                'observerName' => $row->observer_name,
                'reviewedBy' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
                'reviewerName' => $row->reviewer_name,
                'observedAt' => $row->observed_at,
                'reviewedAt' => $row->reviewed_at,
            ])->values(),
            'summary' => [
                'hotelId' => (string) $hotel->id,
                'inspectionDate' => $date,
                'hotelDate' => now($hotel->timezone ?: 'Asia/Manila')->toDateString(),
                'roomCount' => $roomCount,
                'missing' => $missing,
                'assigned' => $assigned,
                'submitted' => $submitted,
                'verified' => $verified,
                'flagged' => $flagged,
                'observed' => $submitted + $verified + $flagged,
                'reviewed' => $verified + $flagged,
                'unresolved' => $missing + $assigned + $submitted + $flagged - (int) $totals->resolved_count,
                'resolvedInvestigations' => (int) $totals->resolved_count,
                'checked' => $roomCount - $missing,
                'unexpectedOccupied' => (int) $totals->unexpected_occupied_count,
                'expectedOccupiedVacant' => (int) $totals->expected_occupied_vacant_count,
                'unverified' => (int) $totals->unverified_count,
                'matching' => (int) $totals->match_count,
                'coveragePercent' => $roomCount === 0 ? 0 : round(($roomCount - $missing) * 100 / $roomCount, 1),
                'observationPercent' => $roomCount === 0 ? 0 : round(($submitted + $verified + $flagged) * 100 / $roomCount, 1),
                'historicalSnapshotOnly' => true,
                'roomCatalogAsOfReportRun' => true,
            ],
            'meta' => [
                'page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage(),
                'filter' => $filter,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class BusinessAuditController
{
    // Category, source table, subject ID, actor ID, timestamp, event column,
    // fixed action (when the source is a record rather than an event).
    private const SOURCES = [
        ['bookings', 'reservation_booking_events', 'reservation_record_id', 'actor_id', 'created_at', 'event_type', null],
        ['housekeeping', 'housekeeping_task_events', 'task_id', 'actor_id', 'created_at', 'action', null],
        ['maintenance', 'maintenance_work_order_events', 'work_order_id', 'actor_id', 'created_at', 'action', null],
        ['staff', 'hotel_staff_events', 'target_user_id', 'actor_id', 'created_at', 'action', null],
        ['catalog', 'catalog_audit_logs', 'subject_id', 'actor_id', 'created_at', null, 'catalog_updated'],
        ['payments', 'folio_payment_records', 'id', 'recorded_by', 'created_at', null, 'payment_recorded'],
        ['payments', 'folio_payment_voids', 'payment_record_id', 'voided_by', 'created_at', null, 'payment_voided'],
        ['payments', 'folio_payment_duplicate_overrides', 'payment_record_id', 'approved_by', 'created_at', null, 'duplicate_override_approved'],
        ['payments', 'folio_settlement_verifications', 'payment_record_id', 'prepared_by', 'created_at', null, 'verification_prepared'],
        ['payments', 'folio_settlement_verifications', 'payment_record_id', 'reviewed_by', 'reviewed_at', null, 'verification_reviewed'],
        ['payments', 'folio_settlement_allocations', 'payment_record_id', 'allocated_by', 'created_at', null, 'payment_allocated'],
        ['payments', 'folio_settlement_signoffs', 'folio_id', 'approved_by', 'signed_at', null, 'folio_signed_off'],
        ['payments', 'folio_adjustments', 'folio_id', 'actor_id', 'created_at', 'kind', null],
        ['cashier', 'cashier_shift_events', 'shift_id', 'actor_id', 'created_at', 'event', null],
        ['expenses', 'hotel_expenses', 'id', 'recorded_by', 'created_at', null, 'expense_recorded'],
        ['expenses', 'hotel_expense_reviews', 'expense_id', 'reviewed_by', 'created_at', 'decision', null],
        ['expenses', 'hotel_expense_corrections', 'expense_id', 'requested_by', 'created_at', 'kind', null],
        ['expenses', 'hotel_expense_correction_decisions', 'correction_id', 'reviewed_by', 'created_at', 'decision', null],
        ['expenses', 'hotel_expense_policy_events', 'id', 'actor_id', 'created_at', null, 'expense_policy_updated'],
        ['finance', 'financial_posting_journals', 'id', 'posted_by', 'posted_at', null, 'journal_posted'],
        ['finance', 'financial_day_closes', 'id', 'closed_by', 'closed_at', null, 'business_day_closed'],
    ];

    public function __invoke(Request $request, Hotel $hotel): JsonResponse
    {
        $categories = array_values(array_unique(array_column(self::SOURCES, 0)));
        $input = $request->validate([
            'category' => ['sometimes', Rule::in($categories)],
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string', 'max:1000'],
        ]);
        $days = (int) ($input['days'] ?? 30);
        $since = now()->subDays($days);
        $union = null;

        foreach (self::SOURCES as $rank => [$category, $table, $subject, $actor, $date, $action, $fixed]) {
            if (isset($input['category']) && $input['category'] !== $category) {
                continue;
            }

            // All identifiers are hard-coded above, never taken from input.
            // Select only safe metadata, not notes, snapshots or payment evidence.
            $query = DB::table($table)
                ->where('hotel_id', $hotel->id)
                ->whereNotNull($date)
                ->where($date, '>=', $since)
                ->select(['id as event_id', $subject.' as subject_id', $actor.' as actor_id', $date.' as event_at'])
                ->selectRaw(($rank + 1).' as source_rank');
            if ($action === null) {
                $query->selectRaw('? as action_code', [$fixed]);
            } else {
                $query->addSelect($action.' as action_code');
            }
            $union = $union === null ? $query : $union->unionAll($query);
        }

        // Cursor pagination avoids scanning and counting the entire history for
        // every page. The rank/id pair disambiguates same-second timestamps.
        $page = DB::query()->fromSub($union, 'audit_events')
            ->orderByDesc('event_at')->orderByDesc('source_rank')->orderByDesc('event_id')
            ->cursorPaginate((int) ($input['per_page'] ?? 25));

        $items = $page->getCollection()->map(function (object $row): array {
            [$category, $source] = self::SOURCES[(int) $row->source_rank - 1];

            return [
                'id' => $row->source_rank.':'.$row->event_id,
                'category' => $category,
                'source' => $source,
                'subjectId' => (string) $row->subject_id,
                'actorId' => $row->actor_id === null ? null : (string) $row->actor_id,
                'action' => (string) $row->action_code,
                'createdAt' => (string) $row->event_at,
            ];
        })->values();

        return response()->json([
            'data' => ['hotelId' => (string) $hotel->id, 'items' => $items],
            'meta' => [
                'readOnly' => true,
                'days' => $days,
                'categories' => $categories,
                'nextCursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }
}

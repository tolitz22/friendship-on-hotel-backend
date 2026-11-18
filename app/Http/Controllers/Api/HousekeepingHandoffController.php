<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Manager-only handoff queue; never changes room readiness.
 * Pending checkout cleaning with no assigned user is an actionable handoff.
 * Aging is measured from task creation, not from the scheduled departure.
 */
final class HousekeepingHandoffController
{
    public function myAssignments(\Illuminate\Http\Request $request, Hotel $hotel): JsonResponse
    {
        $staff = $request->user();
        abort_unless($staff !== null, 403);
        // The current hotel's membership, not just a bearer-token ID, must
        // authorize the staff member to view housekeeping assignments.
        $member = DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
            ->where('user_id', $staff->id)->where('is_active', true)->first();
        abort_unless($member !== null && in_array($member->role, ['housekeeping', 'admin'], true), 403);

        $tasks = DB::table('housekeeping_tasks as task')
            ->join('rooms as room', function ($join): void {
                $join->on('room.id', '=', 'task.room_id')
                    ->on('room.hotel_id', '=', 'task.hotel_id');
            })
            ->where('task.hotel_id', $hotel->id)
            ->where('task.assigned_user_id', $staff->id)
            ->whereIn('task.status', ['todo', 'in_progress'])
            ->orderByDesc('task.updated_at')->limit(20)
            ->get(['task.id', 'task.status', 'task.type', 'task.due_at',
                'task.updated_at', 'room.number as room_number']);

        return response()->json([
            'data' => $tasks->map(fn (object $task): array => [
                'taskId' => (string) $task->id,
                'roomNumber' => $task->room_number,
                'type' => $task->type, 'status' => $task->status,
                'dueAt' => $task->due_at,
                'assignedTaskUpdatedAt' => (string) $task->updated_at,
            ])->values(),
            'meta' => ['limitedTo' => 20, 'inAppPollingOnly' => true,
                'externalNotificationsConfigured' => false,
                'changesReadiness' => false],
        ]);
    }

    public function __invoke(Hotel $hotel): JsonResponse
    {
        $query = DB::table('housekeeping_tasks as task')
            ->join('rooms as room', function ($join): void {
                $join->on('room.id', '=', 'task.room_id')
                    ->on('room.hotel_id', '=', 'task.hotel_id');
            })
            ->where('task.hotel_id', $hotel->id)
            ->where('task.type', 'checkout_clean')
            ->whereIn('task.status', ['todo', 'in_progress'])
            ->whereNull('task.assigned_user_id');

        $total = (clone $query)->count();
        $rows = $query->orderBy('task.created_at')->orderBy('task.id')
            ->limit(100)->get([
                'task.id', 'task.room_id', 'room.number as room_number',
                'task.status', 'task.created_at', 'task.due_at',
                'task.revision', 'task.assigned_to',
            ]);

        $now = now();
        return response()->json([
            'data' => $rows->map(function (object $task) use ($now): array {
                $ageMinutes = max(0, (int) \Carbon\Carbon::parse($task->created_at)->diffInMinutes($now));
                $priority = $ageMinutes >= 120 ? 'escalate'
                    : ($ageMinutes >= 30 ? 'attention' : 'new');
                return [
                    'taskId' => (string) $task->id,
                    'roomId' => (string) $task->room_id,
                    'roomNumber' => $task->room_number,
                    'status' => $task->status,
                    'assignedNameText' => $task->assigned_to,
                    'createdAt' => (string) $task->created_at,
                    'dueAt' => $task->due_at,
                    'ageMinutes' => $ageMinutes,
                    'priority' => $priority,
                    'revision' => (int) $task->revision,
                    'action' => 'Assign an authorized housekeeping employee; verify cleaning and request new independent inspection.',
                ];
            })->values(),
            'meta' => [
                'total' => $total, 'limitedTo' => 100, 'hasMore' => $total > 100,
                'ageAttentionMinutes' => 30, 'ageEscalationMinutes' => 120,
                'readOnly' => true, 'changesReadiness' => false,
                'notificationDeliveryConfigured' => false,
            ],
        ]);
    }
}

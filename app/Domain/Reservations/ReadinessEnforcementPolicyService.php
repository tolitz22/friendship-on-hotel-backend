<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReadinessEnforcementPolicyService
{
    public function show(Hotel $hotel): array
    {
        $row = DB::table('hotel_readiness_policies')
            ->where('hotel_id', $hotel->id)->first();
        return [
            'hotelId' => (string) $hotel->id,
            'enforceAtCheckIn' => $row === null ? false : (bool) $row->enforce_at_check_in,
            'revision' => $row === null ? 0 : (int) $row->revision,
            'changedBy' => $row === null ? null : (string) $row->changed_by,
            'updatedAt' => $row?->updated_at,
            'requiresIndependentRoomInspection' => true,
            'paymentsOrIdentityVerified' => false,
        ];
    }

    public function change(Hotel $hotel, int $actor, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $row = DB::table('hotel_readiness_policies')
                ->where('hotel_id', $hotel->id)->first();

            $revision = $row === null ? 0 : (int) $row->revision;
            if ($revision !== (int) $input['expectedRevision']) {
                throw new HttpException(409, 'Readiness policy revision changed. Reload before updating.');
            }
            $previous = $row !== null && (bool) $row->enforce_at_check_in;
            $next = (bool) $input['enforceAtCheckIn'];
            if ($previous === $next) {
                throw new HttpException(409, 'Readiness enforcement already has that setting.');
            }
            $newRevision = $revision + 1;
            $values = [
                'enforce_at_check_in' => $next,
                'revision' => $newRevision,
                'changed_by' => $actor,
                'change_reason' => trim($input['reason']),
                'updated_at' => now(),
            ];
            if ($row === null) {
                DB::table('hotel_readiness_policies')->insert([
                    'hotel_id' => $hotel->id, ...$values, 'created_at' => now(),
                ]);
            } else {
                DB::table('hotel_readiness_policies')->where('hotel_id', $hotel->id)->update($values);
            }
            DB::table('hotel_readiness_policy_events')->insert([
                'hotel_id' => $hotel->id, 'actor_id' => $actor,
                'previous_enabled' => $previous, 'next_enabled' => $next,
                'revision' => $newRevision, 'reason' => trim($input['reason']),
                'created_at' => now(),
            ]);
            return $this->show($hotel);
        }, 3);
    }

    public function history(Hotel $hotel, int $page): array
    {
        $result = DB::table('hotel_readiness_policy_events')
            ->where('hotel_id', $hotel->id)->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        return [
            'data' => $result->getCollection()->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'actorId' => (string) $row->actor_id,
                'previousEnabled' => (bool) $row->previous_enabled,
                'nextEnabled' => (bool) $row->next_enabled,
                'revision' => (int) $row->revision,
                'reason' => $row->reason,
                'createdAt' => $row->created_at,
            ])->values(),
            'meta' => [
                'page' => $result->currentPage(),
                'perPage' => $result->perPage(),
                'total' => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }
}

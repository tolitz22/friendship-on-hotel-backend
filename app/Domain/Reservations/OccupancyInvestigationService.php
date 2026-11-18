<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OccupancyInvestigationService
{
    private function event(Hotel $hotel, int $caseId, int $actor, string $type, array $details): void
    {
        DB::table('occupancy_investigation_events')->insert([
            'hotel_id' => $hotel->id, 'investigation_id' => $caseId, 'actor_id' => $actor,
            'event' => $type, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function base(Hotel $hotel, int $verificationId)
    {
        return DB::table('occupancy_investigations as i')
            ->join('occupancy_verifications as v', function ($join): void {
                $join->on('v.id', '=', 'i.verification_id')->on('v.hotel_id', '=', 'i.hotel_id');
            })
            ->leftJoin('users as owner', 'owner.id', '=', 'i.owner_id')
            ->leftJoin('users as opener', 'opener.id', '=', 'i.opened_by')
            ->leftJoin('users as closer', 'closer.id', '=', 'i.closed_by')
            ->where('i.hotel_id', $hotel->id)->where('i.verification_id', $verificationId)
            ->select('i.*', 'v.status as verification_status', 'v.discrepancy',
                'owner.name as owner_name', 'opener.name as opener_name', 'closer.name as closer_name');
    }

    private function present(object $record): array
    {
        return [
            'id' => (string) $record->id, 'verificationId' => (string) $record->verification_id,
            'status' => $record->status, 'ownerId' => (string) $record->owner_id,
            'ownerName' => $record->owner_name, 'openedBy' => (string) $record->opened_by,
            'openedByName' => $record->opener_name,
            'closedBy' => $record->closed_by === null ? null : (string) $record->closed_by,
            'closedByName' => $record->closer_name, 'resolution' => $record->resolution,
            'closingNote' => $record->closing_note, 'createdAt' => $record->created_at,
            'closedAt' => $record->closed_at, 'discrepancy' => $record->discrepancy,
        ];
    }

    private function requireManager(Hotel $hotel, int $user): void
    {
        $allowed = DB::table('hotel_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.hotel_id', $hotel->id)->where('m.user_id', $user)
            ->where('m.role', 'admin')->where('m.is_active', true)
            ->where('u.is_active', true)->exists();
        abort_unless($allowed, 403);
    }

    public function show(Hotel $hotel, int $verificationId): ?array
    {
        $record = $this->base($hotel, $verificationId)->first();
        if (! $record) return null;

        $events = DB::table('occupancy_investigation_events as e')
            ->leftJoin('users as actor', 'actor.id', '=', 'e.actor_id')
            ->where('e.hotel_id', $hotel->id)->where('e.investigation_id', $record->id)
            ->orderBy('e.id')->get(['e.id', 'e.event', 'e.actor_id', 'actor.name as actor_name',
                'e.details', 'e.created_at']);
        $case = $this->present($record);
        $case['history'] = $events->map(fn (object $row): array => [
            'id' => (string) $row->id, 'event' => $row->event,
            'actorId' => (string) $row->actor_id, 'actorName' => $row->actor_name,
            'details' => json_decode($row->details, true, 512, JSON_THROW_ON_ERROR),
            'createdAt' => $row->created_at,
        ])->all();
        return $case;
    }

    public function open(Hotel $hotel, int $actor, int $verificationId, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $verificationId, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->requireManager($hotel, $actor);
            $verification = DB::table('occupancy_verifications')
                ->where('hotel_id', $hotel->id)->where('id', $verificationId)->lockForUpdate()->first();
            abort_unless($verification, 404);
            if ($verification->status !== 'flagged') {
                throw new HttpException(409, 'Only flagged observations can become investigations.');
            }
            if ($this->base($hotel, $verificationId)->exists()) {
                throw new HttpException(409, 'This room check already has an investigation.');
            }
            $this->requireManager($hotel, (int) $input['ownerId']);
            $id = DB::table('occupancy_investigations')->insertGetId([
                'hotel_id' => $hotel->id, 'verification_id' => $verificationId,
                'owner_id' => $input['ownerId'], 'opened_by' => $actor, 'status' => 'open',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($hotel, $id, $actor, 'opened', [
                'ownerId' => (string) $input['ownerId'], 'note' => trim($input['note']),
            ]);
            return $this->show($hotel, $verificationId);
        }, 3);
    }

    public function update(Hotel $hotel, int $actor, int $verificationId, array $input): array
    {
        return DB::transaction(function () use ($hotel, $actor, $verificationId, $input): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->requireManager($hotel, $actor);
            $record = $this->base($hotel, $verificationId)->lockForUpdate()->first();
            abort_unless($record, 404);
            if ($record->status !== 'open') {
                throw new HttpException(409, 'This investigation is closed. Its history cannot be rewritten.');
            }
            $action = $input['action'];
            if ($action === 'note') {
                if ((int) $record->owner_id !== $actor) {
                    throw new HttpException(403, 'Only the assigned investigation owner may record findings.');
                }
                $this->event($hotel, (int) $record->id, $actor, 'finding', [
                    'note' => trim($input['note']),
                    'evidenceReference' => trim($input['evidenceReference'] ?? ''),
                ]);
            } elseif ($action === 'assign') {
                $owner = (int) $input['ownerId'];
                $this->requireManager($hotel, $owner);
                DB::table('occupancy_investigations')->where('id', $record->id)->update([
                    'owner_id' => $owner, 'updated_at' => now(),
                ]);
                $this->event($hotel, (int) $record->id, $actor, 'reassigned', [
                    'previousOwnerId' => (string) $record->owner_id,
                    'ownerId' => (string) $owner, 'note' => trim($input['note']),
                ]);
            } elseif ($action === 'close') {
                if ((int) $record->owner_id === $actor) {
                    throw new HttpException(403, 'A different manager must close an investigation after independent review.');
                }
                $findings = DB::table('occupancy_investigation_events')
                    ->where('hotel_id', $hotel->id)->where('investigation_id', $record->id)
                    ->where('event', 'finding')->exists();
                if (! $findings) {
                    throw new HttpException(409, 'Record investigation findings before closure.');
                }
                DB::table('occupancy_investigations')->where('id', $record->id)->update([
                    'status' => 'closed', 'resolution' => $input['resolution'],
                    'closing_note' => trim($input['note']), 'closed_by' => $actor,
                    'closed_at' => now(), 'updated_at' => now(),
                ]);
                $this->event($hotel, (int) $record->id, $actor, 'closed', [
                    'resolution' => $input['resolution'], 'note' => trim($input['note']),
                ]);
            } else {
                throw new HttpException(422, 'Unsupported investigation action.');
            }
            return $this->show($hotel, $verificationId);
        }, 3);
    }
}

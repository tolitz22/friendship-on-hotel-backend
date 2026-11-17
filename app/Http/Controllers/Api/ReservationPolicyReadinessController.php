<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReservationPolicyDraft;
use App\Domain\Reservations\ReservationPolicyReadiness;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ReservationPolicyReadinessController
{
    public function __invoke(Hotel $hotel, ReservationPolicyReadiness $readiness): JsonResponse
    {
        $row = DB::table('reservation_policy_drafts')->where('hotel_id', $hotel->id)->first();
        $decisions = $row === null ? ReservationPolicyDraft::empty()
            : array_replace(ReservationPolicyDraft::empty(), json_decode($row->decisions, true, 512, JSON_THROW_ON_ERROR));

        return response()->json([
            'data' => [
                'hotelId' => (string) $hotel->id,
                'draftRevision' => $row === null ? 0 : (int) $row->revision,
                ...$readiness->assess($decisions),
            ],
            'meta' => ['diagnosticOnly' => true, 'effectiveForBookings' => false],
        ]);
    }
}

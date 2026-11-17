<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReservationPolicyDraft;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReservationPolicyDraftController
{
    private function serialize(?object $row, Hotel $hotel): array
    {
        $decisions = $row === null
            ? ReservationPolicyDraft::empty()
            : array_replace(ReservationPolicyDraft::empty(), json_decode($row->decisions, true, 512, JSON_THROW_ON_ERROR));
        return [
            'hotelId' => (string) $hotel->id,
            'revision' => $row === null ? 0 : (int) $row->revision,
            'status' => 'draft',
            'effectiveForBookings' => false,
            'decisions' => $decisions,
            'completion' => ReservationPolicyDraft::completion($decisions),
        ];
    }

    public function show(Hotel $hotel): JsonResponse
    {
        $row = DB::table('reservation_policy_drafts')->where('hotel_id', $hotel->id)->first();
        return response()->json(['data' => $this->serialize($row, $hotel)]);
    }

    public function update(Request $request, Hotel $hotel): JsonResponse
    {
        $rules = [
            'expectedRevision' => ['required', 'integer', 'min:0'],
            'decisions' => ['required', 'array', 'min:1'],
        ];
        foreach (ReservationPolicyDraft::FIELDS as $field => $options) {
            $rules['decisions.'.$field] = ['sometimes', 'nullable', 'string', Rule::in($options)];
        }
        $data = Validator::make($request->all(), $rules)->validate();
        $submitted = $data['decisions'];
        $unknown = array_diff(array_keys($submitted), array_keys(ReservationPolicyDraft::FIELDS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['decisions' => ['Unknown policy decision fields: '.implode(', ', $unknown)]]);
        }

        $result = DB::transaction(function () use ($hotel, $request, $data, $submitted): array {
            // Serialize draft creation, revision checks and updates across both MySQL/InnoDB and PostgreSQL.
            DB::table('hotels')->where('id', $hotel->id)->lockForUpdate()->first();
            $existing = DB::table('reservation_policy_drafts')->where('hotel_id', $hotel->id)->first();
            $current = $this->serialize($existing, $hotel);
            if ($current['revision'] !== (int) $data['expectedRevision']) {
                return ['conflict' => true, 'current' => $current];
            }

            $nextDecisions = array_replace($current['decisions'], $submitted);
            if ($nextDecisions === $current['decisions']) {
                return ['conflict' => false, 'current' => $current];
            }

            $revision = $current['revision'] + 1;
            $serialized = json_encode($nextDecisions, JSON_THROW_ON_ERROR);
            if ($existing) {
                DB::table('reservation_policy_drafts')->where('hotel_id', $hotel->id)->update([
                    'revision' => $revision,
                    'decisions' => $serialized,
                    'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('reservation_policy_drafts')->insert([
                    'hotel_id' => $hotel->id,
                    'revision' => $revision,
                    'decisions' => $serialized,
                    'updated_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('reservation_policy_draft_revisions')->insert([
                'hotel_id' => $hotel->id,
                'revision' => $revision,
                'decisions' => $serialized,
                'actor_id' => $request->user()->id,
                'created_at' => now(),
            ]);

            $updated = DB::table('reservation_policy_drafts')->where('hotel_id', $hotel->id)->first();
            return ['conflict' => false, 'current' => $this->serialize($updated, $hotel)];
        });

        if ($result['conflict']) {
            return response()->json([
                'message' => 'The policy draft has changed. Reload it before saving again.',
                'data' => $result['current'],
            ], 409);
        }
        return response()->json(['data' => $result['current']]);
    }

    public function history(Request $request, Hotel $hotel): JsonResponse
    {
        $input = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $page = DB::table('reservation_policy_draft_revisions')
            ->where('hotel_id', $hotel->id)
            ->orderByDesc('revision')
            ->paginate((int) ($input['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (object $row): array => [
                'revision' => (int) $row->revision,
                'actorId' => (string) $row->actor_id,
                'decisions' => json_decode($row->decisions, true, 512, JSON_THROW_ON_ERROR),
                'createdAt' => $row->created_at,
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }
}

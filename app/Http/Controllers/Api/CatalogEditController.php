<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CatalogEditController
{
    public function updateType(Request $request, Hotel $hotel, string $type): JsonResponse
    {
        $record = $hotel->roomTypes()->findOrFail($type);
        $data = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:120',
                Rule::unique('room_types', 'name')->where('hotel_id', $hotel->id)->ignore($record->id)],
            'maxOccupancy' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
        ])->validate();

        $result = DB::transaction(function () use ($request, $hotel, $record, $data): RoomType {
            $type = $hotel->roomTypes()->lockForUpdate()->findOrFail($record->id);
            $before = ['name' => $type->name, 'maxOccupancy' => (int) $type->max_occupancy];

            if (array_key_exists('maxOccupancy', $data)
                && (int) $data['maxOccupancy'] !== (int) $type->max_occupancy
                && DB::table('reservation_records')->where('hotel_id', $hotel->id)
                    ->where('room_type_id', $type->id)->exists()) {
                throw ValidationException::withMessages([
                    'maxOccupancy' => ['Capacity changes are blocked while this room type has reservation records.'],
                ]);
            }

            if (isset($data['name'])) {
                $name = trim($data['name']);
                if ($name === '') {
                    throw ValidationException::withMessages(['name' => ['A nonempty name is required.']]);
                }
                $type->name = $name;
            }

            if (isset($data['maxOccupancy'])) {
                $type->max_occupancy = (int) $data['maxOccupancy'];
            }

            $after = ['name' => $type->name, 'maxOccupancy' => (int) $type->max_occupancy];
            if ($before !== $after) {
                $type->save();
                $this->recordAudit($hotel, $request, 'room_type', $type->id, $before, $after);
            }

            return $type;
        });

        return response()->json(['data' => [
            'id' => (string) $result->id,
            'hotelId' => (string) $hotel->id,
            'name' => $result->name,
            'maxOccupancy' => (int) $result->max_occupancy,
        ]]);
    }

    public function updateRoom(Request $request, Hotel $hotel, string $room): JsonResponse
    {
        $record = $hotel->rooms()->findOrFail($room);
        $data = Validator::make($request->all(), [
            'number' => ['sometimes', 'required', 'string', 'max:32',
                Rule::unique('rooms', 'number')->where('hotel_id', $hotel->id)->ignore($record->id)],
            'floor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'roomTypeId' => ['sometimes', 'required', 'integer'],
        ])->validate();

        $result = DB::transaction(function () use ($request, $hotel, $record, $data): Room {
            $room = $hotel->rooms()->lockForUpdate()->findOrFail($record->id);
            $before = [
                'number' => $room->number,
                'floor' => $room->floor_number === null ? null : (int) $room->floor_number,
                'roomTypeId' => (string) $room->room_type_id,
            ];

            if (isset($data['roomTypeId']) && (int) $data['roomTypeId'] !== (int) $room->room_type_id) {
                $newType = $hotel->roomTypes()->find($data['roomTypeId']);
                if (! $newType) {
                    throw ValidationException::withMessages([
                        'roomTypeId' => ['The room type does not belong to this property.'],
                    ]);
                }
                // A type reassignment could invalidate room-type capacity and reservation assignment contracts.
                // Until reservation policies are approved, prohibit changes affecting referenced inventory.
                $referenced = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                    ->where(function ($query) use ($room): void {
                        $query->where('assigned_room_id', $room->id)
                            ->orWhere('room_type_id', $room->room_type_id);
                    })->exists();
                if ($referenced) {
                    throw ValidationException::withMessages([
                        'roomTypeId' => ['Room type reassignment is blocked when the room or its current type has reservation records.'],
                    ]);
                }
                $room->room_type_id = $newType->id;
            }

            if (isset($data['number'])) {
                $number = trim($data['number']);
                if ($number === '') {
                    throw ValidationException::withMessages(['number' => ['A nonempty room number is required.']]);
                }
                $room->number = $number;
            }
            if (array_key_exists('floor', $data)) {
                $room->floor_number = $data['floor'];
            }

            $after = [
                'number' => $room->number,
                'floor' => $room->floor_number === null ? null : (int) $room->floor_number,
                'roomTypeId' => (string) $room->room_type_id,
            ];
            if ($before !== $after) {
                $room->save();
                $this->recordAudit($hotel, $request, 'room', $room->id, $before, $after);
            }
            return $room->load('roomType');
        });

        return response()->json(['data' => [
            'id' => (string) $result->id,
            'hotelId' => (string) $hotel->id,
            'number' => $result->number,
            'floor' => $result->floor_number,
            'roomTypeId' => (string) $result->room_type_id,
            'roomTypeName' => $result->roomType->name,
            'maxOccupancy' => (int) $result->roomType->max_occupancy,
        ]]);
    }

    public function audit(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'subjectType' => ['required', Rule::in(['room', 'room_type'])],
            'subjectId' => ['required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $subject = $data['subjectType'] === 'room'
            ? $hotel->rooms()->findOrFail($data['subjectId'])
            : $hotel->roomTypes()->findOrFail($data['subjectId']);

        $page = DB::table('catalog_audit_logs')->where('hotel_id', $hotel->id)
            ->where('subject_type', $data['subjectType'])
            ->where('subject_id', $subject->id)
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'subjectType' => $row->subject_type,
                'subjectId' => (string) $row->subject_id,
                'actorId' => (string) $row->actor_id,
                'before' => json_decode($row->changes_before, true),
                'after' => json_decode($row->changes_after, true),
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

    private function recordAudit(Hotel $hotel, Request $request, string $subjectType, int $id, array $before, array $after): void
    {
        DB::table('catalog_audit_logs')->insert([
            'hotel_id' => $hotel->id,
            'actor_id' => $request->user()->id,
            'subject_type' => $subjectType,
            'subject_id' => $id,
            'changes_before' => json_encode($before, JSON_THROW_ON_ERROR),
            'changes_after' => json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}

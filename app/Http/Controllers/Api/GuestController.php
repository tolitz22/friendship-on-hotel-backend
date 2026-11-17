<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class GuestController
{
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $params = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $hotel->guests();

        if (isset($params['search']) && trim($params['search']) !== '') {
            $term = mb_strtolower(trim($params['search']));
            // Escaping wildcard metacharacters makes literal searching consistent.
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
            $pattern = '%'.$term.'%';
            $query->where(function ($builder) use ($pattern): void {
                foreach (['first_name', 'last_name', 'email', 'phone'] as $column) {
                    $builder->orWhereRaw('LOWER('.$column.') LIKE ? ESCAPE \'!\'', [$pattern]);
                }
            });
        }

        $page = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate((int) ($params['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Guest $guest): array => self::present($guest))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Hotel $hotel, string $guest): JsonResponse
    {
        $record = $hotel->guests()->findOrFail($guest);

        return response()->json(['data' => self::present($record)]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data = Validator::make($request->all(), self::rules(false))->validate();
        $guest = $hotel->guests()->create(self::toColumns($data));

        return response()->json(['data' => self::present($guest)], 201);
    }

    public function update(Request $request, Hotel $hotel, string $guest): JsonResponse
    {
        // Scope the target lookup to the hotel, even for authorized administrators.
        $record = $hotel->guests()->findOrFail($guest);
        $data = Validator::make($request->all(), self::rules(true))->validate();
        $record->fill(self::toColumns($data));
        $record->save();

        return response()->json(['data' => self::present($record)]);
    }

    private static function rules(bool $partial): array
    {
        $name = $partial ? 'sometimes' : 'required';

        return [
            'firstName' => [$name, 'required', 'string', 'min:1', 'max:120'],
            'lastName' => [$name, 'required', 'string', 'min:1', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    private static function toColumns(array $data): array
    {
        $columns = [];
        foreach ([
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'email' => 'email',
            'phone' => 'phone',
            'nationality' => 'nationality',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = is_string($data[$input]) ? trim($data[$input]) : $data[$input];
            }
        }

        return $columns;
    }

    private static function present(Guest $guest): array
    {
        return [
            'id' => (string) $guest->id,
            'hotelId' => (string) $guest->hotel_id,
            'firstName' => $guest->first_name,
            'lastName' => $guest->last_name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'nationality' => $guest->nationality,
            'createdAt' => $guest->created_at->toIso8601String(),
        ];
    }
}

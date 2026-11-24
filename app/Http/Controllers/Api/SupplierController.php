<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\SupplierDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupplierController
{
    public function index(Request $request, Hotel $hotel, SupplierDirectoryService $service): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', 'in:active,inactive,all'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = DB::table('hotel_suppliers')->where('hotel_id', $hotel->id);
        $status = $data['status'] ?? 'active';
        if ($status !== 'all') $query->where('is_active', $status === 'active');
        if (isset($data['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['search'])).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$term])
                    ->orWhereRaw("LOWER(contact_name) LIKE LOWER(?) ESCAPE '!'", [$term]);
            });
        }
        $page = $query->orderBy('normalized_name')->orderBy('id')->paginate((int) ($data['per_page'] ?? 20));
        return response()->json(['data' => $page->getCollection()->map(fn ($row) => $service->present($row))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'totalPages' => $page->lastPage()]]);
    }

    public function show(Hotel $hotel, string $supplier, SupplierDirectoryService $service): JsonResponse
    {
        $row = DB::table('hotel_suppliers')->where('hotel_id', $hotel->id)->where('id', $supplier)->first();
        abort_unless($row !== null, 404);
        return response()->json(['data' => $service->present($row)]);
    }

    public function store(Request $request, Hotel $hotel, SupplierDirectoryService $service): JsonResponse
    {
        return response()->json(['data' => $service->save($hotel, (int) $request->user()->id, null, $request->all())], 201);
    }

    public function update(Request $request, Hotel $hotel, string $supplier, SupplierDirectoryService $service): JsonResponse
    {
        return response()->json(['data' => $service->save($hotel, (int) $request->user()->id, (int) $supplier, $request->all())]);
    }
}

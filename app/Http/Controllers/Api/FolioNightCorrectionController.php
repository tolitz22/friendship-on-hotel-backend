<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\FolioNightCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FolioNightCorrectionController
{
    public function index(Hotel $hotel, string $booking, string $assessment): JsonResponse
    {
        $this->assertOwned($hotel, $booking, $assessment);
        return response()->json(['data' => DB::table('financial_folio_night_corrections')
            ->where('hotel_id', $hotel->id)->where('assessment_id', (int) $assessment)
            ->orderBy('id')->get()]);
    }

    public function prepare(Request $request, Hotel $hotel, string $booking, string $assessment,
        FolioNightCorrectionService $service): JsonResponse
    {
        $this->assertOwned($hotel, $booking, $assessment);
        $data = $request->validate([
            'benefitKind' => ['required', 'in:none,senior,pwd'],
            'eligibleGrossMinor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'evidenceReference' => ['nullable', 'string', 'min:6', 'max:160'],
            'reason' => ['required', 'string', 'min:15', 'max:500'],
        ]);
        return response()->json(['data' => $service->prepare(
            (int) $hotel->id, (int) $assessment, (int) $request->user()->id,
            $data['benefitKind'], (int) $data['eligibleGrossMinor'],
            $data['evidenceReference'] ?? null, $data['reason']
        )], 201);
    }

    public function approve(Request $request, Hotel $hotel, string $booking, string $assessment,
        string $correction, FolioNightCorrectionService $service): JsonResponse
    {
        $this->assertOwned($hotel, $booking, $assessment);
        $request->validate(['acknowledged' => ['required', 'accepted']]);
        abort_unless(DB::table('financial_folio_night_corrections')
            ->where('hotel_id', $hotel->id)->where('assessment_id', (int) $assessment)
            ->where('id', (int) $correction)->exists(), 404);
        return response()->json(['data' => $service->approve(
            (int) $hotel->id, (int) $correction, (int) $request->user()->id,
        )]);
    }

    private function assertOwned(Hotel $hotel, string $booking, string $assessment): void
    {
        abort_unless(DB::table('financial_folio_night_assessments')
            ->where('hotel_id', $hotel->id)
            ->where('reservation_record_id', (int) $booking)
            ->where('id', (int) $assessment)->exists(), 404);
    }
}

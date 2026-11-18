<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\Finance\FolioNightAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FolioNightAssessmentController
{
    public function index(Hotel $hotel, string $booking, FolioNightAssessmentService $service): JsonResponse
    {
        $folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)
            ->where('reservation_record_id', (int) $booking)->first();
        abort_unless($folio !== null, 404);
        return response()->json(['data' => $service->listing((int) $hotel->id, (int) $folio->id)]);
    }

    public function prepare(Request $request, Hotel $hotel, string $booking,
        FolioNightAssessmentService $service): JsonResponse
    {
        $data = $request->validate([
            'folioEntryId' => ['required', 'integer', 'min:1'],
            'taxPolicyId' => ['required', 'integer', 'min:1'],
            'benefitKind' => ['required', 'in:none,senior,pwd'],
            'eligibleGrossMinor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'evidenceReference' => ['nullable', 'string', 'min:6', 'max:160'],
        ]);
        // Verify the folio entry belongs to the hotel AND requested reservation.
        abort_unless(DB::table('folio_entries as e')
            ->join('reservation_folios as f', 'f.id', '=', 'e.folio_id')
            ->where('e.hotel_id', $hotel->id)->where('f.hotel_id', $hotel->id)
            ->where('f.reservation_record_id', (int) $booking)
            ->where('e.id', $data['folioEntryId'])->exists(), 404);
        return response()->json(['data' => $service->prepare(
            (int) $hotel->id, (int) $data['folioEntryId'], (int) $data['taxPolicyId'],
            (int) $request->user()->id, $data['benefitKind'],
            (int) $data['eligibleGrossMinor'], $data['evidenceReference'] ?? null,
        )], 201);
    }

    public function approve(Request $request, Hotel $hotel, string $booking, string $assessment,
        FolioNightAssessmentService $service): JsonResponse
    {
        $request->validate(['acknowledged' => ['required', 'accepted']]);
        abort_unless(DB::table('financial_folio_night_assessments')
            ->where('hotel_id', $hotel->id)->where('reservation_record_id', (int) $booking)
            ->where('id', (int) $assessment)->exists(), 404);
        return response()->json(['data' => $service->approve(
            (int) $hotel->id, (int) $assessment, (int) $request->user()->id,
        )]);
    }

    public function recognize(Request $request, Hotel $hotel, string $booking, string $assessment,
        FolioNightAssessmentService $service): JsonResponse
    {
        $request->validate(['acknowledged' => ['required', 'accepted']]);
        abort_unless(DB::table('financial_folio_night_assessments')
            ->where('hotel_id', $hotel->id)->where('reservation_record_id', (int) $booking)
            ->where('id', (int) $assessment)->exists(), 404);
        $journalId = $service->recognize((int) $hotel->id, (int) $assessment, (int) $request->user()->id);
        return response()->json(['data' => ['journalId' => (string) $journalId]]);
    }
}

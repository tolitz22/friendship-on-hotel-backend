<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\NightAuditReviewService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NightAuditReviewController
{
    public function show(Request $request, Hotel $hotel, NightAuditReviewService $service): JsonResponse
    {
        $data = $request->validate(['date'=>['required','date_format:Y-m-d']]);
        return response()->json($service->show($hotel,$data['date']));
    }

    public function store(Request $request, Hotel $hotel, NightAuditReviewService $service): JsonResponse
    {
        $data = $request->validate([
            'date'=>['required','date_format:Y-m-d'],
            'decision'=>['required','in:clear,flag'],
            'note'=>['required','string','min:15','max:2000'],
            'acknowledged'=>['required','accepted'],
        ]);
        $note=trim($data['note']);
        if (mb_strlen($note)<15) {
            return response()->json(['message'=>'Provide a substantive manager review note.'],422);
        }
        return response()->json($service->record(
            $hotel,(int)$request->user()->id,$data['date'],$note,$data['decision']
        ),201);
    }
}

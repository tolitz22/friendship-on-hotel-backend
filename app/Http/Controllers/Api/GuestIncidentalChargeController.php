<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FolioAccountingService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GuestIncidentalChargeController
{
    public function __invoke(Request $request, Hotel $hotel, string $booking,
        FolioAccountingService $accounting): JsonResponse
    {
        $input = $request->validate([
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'category' => ['required', 'in:Extra service,Food and drinks,Minibar,Laundry,Extra person,Other incidental'],
            'description' => ['required', 'string', 'min:3', 'max:250'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
        $result = $accounting->adjust($hotel, (int) $booking, (int) $request->user()->id, [
            'requestKey' => $input['requestKey'],
            'kind' => 'debit',
            'amountMinor' => $input['amountMinor'],
            'reason' => $input['category'].': '.trim($input['description']),
            'routineIncidental' => true,
        ]);
        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }
}

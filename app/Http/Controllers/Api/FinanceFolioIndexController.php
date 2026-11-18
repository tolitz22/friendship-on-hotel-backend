<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FolioAccountingService;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only, tenant-scoped cashier work queue. Never computes a final payable
 * amount or presents staff-confirmed funds as independently verified money.
 * Financial mutations remain in the existing auditable domain services.
 */
final class FinanceFolioIndexController
{
    public function __invoke(Request $request, Hotel $hotel, FolioAccountingService $accounting): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 20);
        $search = trim((string) ($input['search'] ?? ''));

        $nameExpr = DB::connection()->getDriverName() === 'mysql'
            ? "CONCAT(g.first_name, ' ', g.last_name)"
            : "(g.first_name || ' ' || g.last_name)";
        $query = DB::table('reservation_folios as f')
            ->join('reservation_records as b', function ($join): void {
                $join->on('b.id', '=', 'f.reservation_record_id')
                    ->on('b.hotel_id', '=', 'f.hotel_id');
            })
            ->leftJoin('guests as g', function ($join): void {
                $join->on('g.id', '=', 'b.guest_id')
                    ->on('g.hotel_id', '=', 'b.hotel_id');
            })
            ->where('f.hotel_id', $hotel->id);
        if ($search !== '') {
            $query->where(function ($builder) use ($search, $nameExpr): void {
                $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $builder->whereRaw('LOWER(b.reference) LIKE ?', [$like])
                    ->orWhereRaw('LOWER('.$nameExpr.') LIKE ?', [$like]);
                if (ctype_digit($search)) {
                    $builder->orWhere('b.id', (int) $search);
                }
            });
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('b.id')->forPage($page, $perPage)
            ->get(['f.id as folio_id', 'f.currency', 'b.id as booking_id',
                'b.reference', 'b.check_in', 'b.check_out', 'b.lifecycle_state',
                'g.first_name as guest_first_name', 'g.last_name as guest_last_name']);
        // The signed-folio table is introduced by the settlement migration.
        // Older installations can still view charges, but we must not pretend
        // a settlement status has been verified when its records are missing.
        $signoffAvailable = Schema::hasTable('folio_settlement_signoffs');
        $signedFolioIds = $signoffAvailable
            ? DB::table('folio_settlement_signoffs')
                ->where('hotel_id', $hotel->id)
                ->whereIn('folio_id', $rows->pluck('folio_id')->all())
                ->pluck('folio_id')->map(fn ($id) => (string) $id)->flip()
            : collect();

        $data = $rows->map(function (object $row) use ($accounting, $signedFolioIds, $hotel, $signoffAvailable): array {
            // Summary uses the same posted ledger data used by settlement.
            $summary = $accounting->summaryFor((object) [
                'id' => $row->folio_id, 'hotel_id' => $hotel->id,
                'currency' => $row->currency,
            ]);
            return [
                'bookingId' => (string) $row->booking_id,
                'folioId' => (string) $row->folio_id,
                'reference' => (string) $row->reference,
                'guestName' => $row->guest_first_name === null ? null
                    : trim((string) $row->guest_first_name.' '.(string) $row->guest_last_name),
                'checkIn' => (string) $row->check_in,
                'checkOut' => (string) $row->check_out,
                'status' => (string) $row->lifecycle_state,
                'currency' => (string) $row->currency,
                'netRecordedChargesMinor' => $summary['netRecordedChargesMinor'],
                'staffConfirmedPaymentsMinor' => $summary['staffConfirmedPaymentsMinor'],
                'pendingReviewPaymentsMinor' => $summary['pendingReviewPaymentsMinor'],
                'signed' => $signoffAvailable ? $signedFolioIds->has((string) $row->folio_id) : null,
                'provisionalOnly' => true,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page, 'perPage' => $perPage, 'total' => $total,
                'totalPages' => (int) ceil($total / $perPage),
                'amountsProvisional' => true,
                'paymentEvidenceNotIndependentlyVerified' => true,
                'signoffRecordsAvailable' => $signoffAvailable,
            ],
        ]);
    }
}

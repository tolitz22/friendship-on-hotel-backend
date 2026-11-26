<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\FolioSettlementService;
use App\Domain\Reservations\NoShowRefundEligibility;
use App\Domain\Reservations\NoShowWorkflow;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use App\Services\Finance\NoShowLedgerReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NoShowAllocationController
{
    public function show(Request $request, Hotel $hotel, string $booking): JsonResponse
    {
        return response()->json(['data' => DB::transaction(function () use ($request, $hotel, $booking): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorizeActor($request, $hotel);
            return $this->report($hotel, (int) $booking, (int) $request->user()->id);
        }, 3)])->header('Cache-Control', 'private, no-store');
    }

    private function report(Hotel $hotel, int $booking, int $actor): array
    {
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
        abort_unless(DB::table('reservation_records')->where('hotel_id', $hotel->id)
            ->where('id', $booking)->where('lifecycle_state', 'no_show')->exists(), 404);
        $statement = app(FolioSettlementService::class)->statement($hotel, $booking);
        abort_unless($statement['currency'] === 'PHP', 409, 'PHP folio required.');
        $review = app(NoShowRefundEligibility::class)->review($hotel, $booking);
        $ledger = app(NoShowLedgerReconciliationService::class)->report($hotel, $booking, $actor);
        $enabled = config('financial_accounting.enable_guest_deposit_posting', false)
            && config('financial_accounting.enable_no_show_allocation_posting', false)
            && config('financial_accounting.enable_verified_settlement', false)
            && NoShowWorkflow::enabled((int) $hotel->id);
        $open = $clock !== null && $clock->status === 'open'
            && !DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->where('business_date', $clock->business_date)->exists()
            && DB::table('financial_accounting_periods')->where('hotel_id', $hotel->id)->where('status', 'open')
                ->whereDate('starts_on', '<=', $clock->business_date)->whereDate('ends_on', '>=', $clock->business_date)->count() === 1;
        $ready = $enabled && $open && !$statement['signed'] && $ledger['reconciled'] && $review['reconciliationValid'];
        $due = $review['unallocatedFeeMinor'];
        $payments = [];
        foreach ($statement['payments'] as $payment) {
            if ($payment['status'] !== 'staff_confirmed' || $payment['verificationStatus'] !== 'verified') continue;
            $allocated = 0;
            $reserved = 0;
            foreach ($statement['allocations'] as $allocation) {
                if ($allocation['paymentId'] === $payment['id']) $allocated += $allocation['amountMinor'];
            }
            foreach ($statement['refunds'] as $refund) {
                if ($refund['paymentId'] === $payment['id'] && in_array($refund['status'], ['requested', 'approved'], true)) {
                    $reserved += $refund['amountMinor'];
                }
            }
            $available = max(0, $payment['amountMinor'] - $allocated - $reserved);
            $payments[] = ['paymentId' => $payment['id'], 'method' => $payment['method'],
                'availableMinor' => $available, 'maximumMinor' => $ready ? min($available, max(0, $due ?? 0), 100000000000) : 0];
        }
        return ['bookingId' => (string) $booking, 'currency' => 'PHP', 'postingEnabled' => $enabled,
            'reconciled' => $ledger['reconciled'], 'settled' => $ledger['settled'], 'remainingFeeMinor' => $due,
            'availableDepositMinor' => $review['unallocatedVerifiedFundsMinor'],
            'maximumRefundMinor' => $review['maximumRefundRequestMinor'], 'payments' => $payments,
            'blocker' => !$enabled ? 'Allocation journal posting is disabled.' : (!$open ? 'Open financial business date required.'
                : (!$ready ? 'Resolve financial reconciliation and settlement blockers first.' : null))];
    }

    public function allocate(Request $request, Hotel $hotel, string $booking, string $payment): JsonResponse
    {
        $input = $request->validate(['requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'payerKind' => ['required', 'in:guest,company,group'],
            'payerReference' => ['required', 'string', 'min:3', 'max:120'],
            'acknowledged' => ['required', 'accepted']]);
        return DB::transaction(function () use ($request, $hotel, $booking, $payment, $input): JsonResponse {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $this->authorizeActor($request, $hotel);
            $report = $this->report($hotel, (int) $booking, (int) $request->user()->id);
            abort_unless($report['postingEnabled'], 409, 'Allocation posting is disabled.');
            $existing = DB::table('folio_settlement_allocations')->where('hotel_id', $hotel->id)
                ->where('idempotency_key', $input['requestKey'])->exists();
            $maximum = collect($report['payments'])->firstWhere('paymentId', $payment)['maximumMinor'] ?? 0;
            abort_unless($existing || (int) $input['amountMinor'] <= $maximum, 409, 'Allocation eligibility changed; refresh and review.');
            $result = app(FolioSettlementService::class)->allocate($hotel, (int) $booking, (int) $payment,
                (int) $request->user()->id, $input['requestKey'], $input['payerKind'], $input['payerReference'],
                (int) $input['amountMinor'], null);
            return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
        }, 3);
    }

    private function authorizeActor(Request $request, Hotel $hotel): void
    {
        $actor = $request->user()->id;
        abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)->where('user_id', $actor)
            ->where('role', 'admin')->where('is_active', true)->exists()
            && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\FolioAccountingService;
use App\Domain\Reservations\FolioSettlementService;
use App\Domain\Reservations\NoShowRefundEligibility;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Current booking reconciliation, not an as-of trial balance or a closing certification. */
final class NoShowLedgerReconciliationService
{
    public function report(Hotel $hotel, int $bookingId, int $actorId): array
    {
        return DB::transaction(function () use ($hotel, $bookingId, $actorId): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $hotelId = (int) $hotel->id;
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotelId)
                ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
            $folio = app(FolioAccountingService::class)->findFolio($hotel, $bookingId, true);
            abort_unless($folio->currency === 'PHP', 409, 'PHP folio required.');
            $eligibility = app(NoShowRefundEligibility::class)->review($hotel, $bookingId);
            $statement = app(FolioSettlementService::class)->statement($hotel, $bookingId);
            $issues = [];
            $posted = ['deposits' => 0, 'fees' => 0, 'allocations' => 0, 'refunds' => 0];
            $clearance = NoShowRefundEligibility::clearance($hotelId, $bookingId, (int) $folio->id);
            if ($clearance === null || !$eligibility['financialClearanceValid']) {
                $issues[] = ['kind' => 'fee', 'reference' => (string) $bookingId, 'reason' => 'invalid_clearance'];
            } else {
                $posted['fees'] = (int) $clearance->gross_minor;
            }
            foreach ($statement['payments'] as $payment) {
                if ($payment['status'] !== 'staff_confirmed' || $payment['verificationStatus'] !== 'verified') {
                    continue;
                }
                $reference = 'folio-payment-'.$payment['id'];
                $rows = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                    ->where('source_reference', $reference)
                    ->whereIn('source_type', ['guest_deposit_receipt', 'guest_deposit_opening_reclassification'])->get();
                $debit = $rows->count() === 1 && $rows->first()->source_type === 'guest_deposit_opening_reclassification'
                    ? 'guest_deposit_opening_clearing' : ($payment['method'] === 'cash' ? 'cashier_cash' : 'hotel_bank');
                $posted['deposits'] += $this->check($hotelId, $rows, $reference, 'deposit', $debit,
                    'guest_deposit_liability', (int) $payment['amountMinor'], $issues);
            }
            foreach ($statement['allocations'] as $allocation) {
                $reference = 'folio-allocation-'.$allocation['id'];
                $rows = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                    ->where('source_reference', $reference)->where('source_type', 'no_show_deposit_allocation')->get();
                $posted['allocations'] += $this->check($hotelId, $rows, $reference, 'allocation',
                    'guest_deposit_liability', 'AR', (int) $allocation['amountMinor'], $issues);
            }
            $refunds = DB::table('folio_refund_disbursement_evidence')->where('hotel_id', $hotelId)
                ->where('folio_id', $folio->id)->where('status', 'verified')->get();
            foreach ($refunds as $refund) {
                $reference = 'refund-evidence-'.$refund->id;
                $rows = DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                    ->where('source_reference', $reference)->where('source_type', 'no_show_deposit_refund')->get();
                $posted['refunds'] += $this->check($hotelId, $rows, $reference, 'refund',
                    'guest_deposit_liability', 'hotel_bank', (int) $refund->amount_minor, $issues);
            }
            $expected = [
                'deposits' => (int) $eligibility['verifiedPaymentsMinor'],
                'fees' => (int) $statement['netRecordedChargesMinor'],
                'allocations' => (int) $statement['verifiedAllocatedMinor'],
                'refunds' => (int) $eligibility['verifiedRefundsMinor'],
            ];
            $balances = static fn (array $amounts): array => [
                'depositLiabilityMinor' => $amounts['deposits'] - $amounts['allocations'] - $amounts['refunds'],
                'receivableMinor' => $amounts['fees'] - $amounts['allocations'],
            ];
            $valid = $issues === [] && $posted === $expected && $eligibility['reconciliationValid'];
            return [
                'bookingId' => (string) $bookingId, 'currency' => 'PHP',
                'businessDate' => $clock?->business_date, 'scope' => 'current_no_show_matched_journals_only',
                'expectedMinor' => $expected, 'matchedJournalMinor' => $posted,
                'expectedBalances' => $balances($expected), 'matchedJournalBalances' => $balances($posted),
                'issues' => $issues, 'reconciled' => $valid,
                'settled' => $valid && $eligibility['settled'], 'readOnly' => true,
                'closingCertified' => false,
            ];
        }, 3);
    }

    private function check(int $hotelId, object $rows, string $reference, string $kind,
        string $debit, string $credit, int $amount, array &$issues): int
    {
        $reason = null;
        if ($rows->count() !== 1) {
            $reason = $rows->isEmpty() ? 'missing_journal' : 'duplicate_journal';
        } else {
            $journal = $rows->first();
            if (DB::table('financial_posting_journals')->where('hotel_id', $hotelId)
                ->where('reverses_journal_id', $journal->id)->exists()) {
                $reason = 'reversed_journal';
            } else {
                $lines = DB::table('financial_posting_lines')->where('journal_id', $journal->id)->orderBy('line_number')->get();
                if ($journal->currency !== 'PHP' || $amount <= 0 || $lines->count() !== 2
                    || $lines[0]->account_code !== $debit || (int) $lines[0]->debit_minor !== $amount
                    || (int) $lines[0]->credit_minor !== 0 || $lines[1]->account_code !== $credit
                    || (int) $lines[1]->credit_minor !== $amount || (int) $lines[1]->debit_minor !== 0) {
                    $reason = 'journal_mismatch';
                }
            }
        }
        if ($reason !== null) {
            $issues[] = ['kind' => $kind, 'reference' => $reference, 'reason' => $reason];
            return 0;
        }
        return $amount;
    }
}

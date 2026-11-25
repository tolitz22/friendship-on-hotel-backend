<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal accounting primitive. Tax allocation must be independently reviewed
 * before calling; no tax rate or exemption is inferred from a booking policy.
 */
final class NoShowFeeJournalService
{
    public function post(int $hotelId, int $reviewId, int $actorId, int $vatMinor): ?int
    {
        return DB::transaction(function () use ($hotelId, $reviewId, $actorId, $vatMinor): ?int {
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotelId)->lockForUpdate()->first();
            $review = DB::table('no_show_financial_reviews')->where('hotel_id', $hotelId)
                ->where('id', $reviewId)->lockForUpdate()->first();
            abort_unless($review !== null, 404);
            $gross = (int) $review->fee_minor;
            if ($clock === null || $clock->status !== 'open' || $review->currency !== 'PHP'
                || $vatMinor < 0 || $vatMinor > $gross || (int) $review->actor_id === $actorId) {
                throw ValidationException::withMessages(['fee' => 'Open PHP business date and independent fee tax review required.']);
            }
            if ($gross === 0) return null;
            $lines = [['account_code' => 'AR', 'debit_minor' => $gross, 'credit_minor' => 0]];
            if ($gross > $vatMinor) {
                $lines[] = ['account_code' => 'NO_SHOW_REV', 'debit_minor' => 0, 'credit_minor' => $gross - $vatMinor];
            }
            if ($vatMinor > 0) {
                $lines[] = ['account_code' => 'VAT_PAYABLE', 'debit_minor' => 0, 'credit_minor' => $vatMinor];
            }
            return app(FinancialJournalPostingService::class)->post(
                $hotelId, 'no-show-fee-'.$reviewId, 'no_show_fee', 'no-show-review-'.$reviewId,
                'Reviewed no-show fee', $lines, $actorId
            );
        }, 3);
    }
}

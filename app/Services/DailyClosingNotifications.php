<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Reservations\NightAuditPreviewService;
use App\Domain\Reservations\NoShowClosingGuard;
use App\Domain\Reservations\NoShowWorkflow;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

final class DailyClosingNotifications
{
    public const KINDS = ['daily_close_completed', 'daily_close_blocked', 'closing_cashier_unreconciled', 'closing_payment_pending', 'closing_journal_integrity', 'closing_no_show_unresolved', 'closing_room_night_issue'];

    public function all(Hotel $hotel, ?int $limit = null): iterable
    {
        $closed = DB::table('financial_day_closes')->where('hotel_id', $hotel->id)
            ->where('status', 'completed')->where('closed_at', '>=', now()->subDay())
            ->orderByDesc('closed_at')->orderByDesc('id');
        foreach ($limit === null ? $closed->lazy(200) : $closed->limit($limit)->get() as $row) {
            yield $this->item('daily_close_completed', (string) $row->id, (string) $row->business_date, false);
        }

        yield from $this->active($hotel);
    }

    private function active(Hotel $hotel): iterable
    {
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->where('status', 'open')->first();
        if (! $clock || $clock->business_date > now($hotel->timezone ?: 'Asia/Manila')->toDateString()) {
            return;
        }
        $date = (string) $clock->business_date;
        $hotelId = (int) $hotel->id;
        $preview = app(NightAuditPreviewService::class)->preview($hotel, $date)['data'];
        $unbalanced = DB::table('financial_posting_journals as j')
            ->leftJoin('financial_posting_lines as l', 'l.journal_id', '=', 'j.id')
            ->where('j.hotel_id', $hotelId)->where('j.business_date', $date)
            ->groupBy('j.id')->select('j.id')
            ->havingRaw('COUNT(l.id) < 2 OR COALESCE(SUM(l.debit_minor), 0) <> COALESCE(SUM(l.credit_minor), 0) OR SUM(CASE WHEN l.debit_minor < 0 OR l.credit_minor < 0 OR (l.debit_minor = 0 AND l.credit_minor = 0) OR (l.debit_minor > 0 AND l.credit_minor > 0) THEN 1 ELSE 0 END) > 0');
        // The outer query is portable across PostgreSQL and MySQL with strict grouping.
        $journalIssue = DB::query()->fromSub($unbalanced, 'invalid_journals')->exists();
        $noShowIssue = NoShowWorkflow::enabled($hotelId) && NoShowClosingGuard::unresolved($hotelId, $date) > 0;
        $assessments = DB::table('financial_folio_night_assessments as a')->where('a.hotel_id', $hotelId)->where('a.stay_date', $date);
        $nightIssue = (clone $assessments)->where('a.status', '!=', 'approved')->exists();
        $unearned = (clone $assessments)->where('a.status', 'approved')->where(function ($query) use ($hotelId): void {
            $query->whereNull('a.source_journal_id')->orWhereNotExists(function ($recognition) use ($hotelId): void {
                $recognition->selectRaw('1')->from('financial_room_revenue_recognitions as n')
                    ->where('n.hotel_id', $hotelId)->whereColumn('n.source_journal_id', 'a.source_journal_id')
                    ->whereColumn('n.stay_date', 'a.stay_date')
                    ->whereNotExists(function ($reversal) use ($hotelId): void {
                        $reversal->selectRaw('1')->from('financial_posting_journals as x')
                            ->where('x.hotel_id', $hotelId)->whereColumn('x.reverses_journal_id', 'n.recognition_journal_id');
                    });
            });
        })->exists();
        $nightIssue = $nightIssue || $unearned;
        $conditions = [
            'daily_close_blocked' => $preview['openCashierShifts'] > 0 || $journalIssue || $noShowIssue || $nightIssue,
            'closing_cashier_unreconciled' => $preview['unreconciledCashierShifts'] > 0,
            'closing_payment_pending' => $preview['pendingPaymentEvidence'] > 0,
            'closing_journal_integrity' => $journalIssue,
            'closing_no_show_unresolved' => $noShowIssue,
            'closing_room_night_issue' => $nightIssue || $preview['missingNightEntries'] !== [] || $preview['duplicateNightEntries'] !== [],
        ];
        foreach ($conditions as $kind => $present) {
            if ($present) {
                yield $this->item($kind, (string) $clock->id, $date, true);
            }
        }
    }

    private function item(string $kind, string $id, string $date, bool $active): array
    {
        return [
            'id' => $kind.':'.$id.($active ? ':'.$date : ''),
            'kind' => $kind,
            'target' => 'night-audit',
            'closingDate' => $date,
            'reference' => 'Business date '.$date,
            'date' => $date,
        ];
    }

    public function current(Hotel $hotel, string $id): ?array
    {
        $kind = explode(':', $id, 2)[0];
        if (! in_array($kind, self::KINDS, true)) {
            return null;
        }
        if ($kind === 'daily_close_completed') {
            if (! preg_match('/^daily_close_completed:([1-9][0-9]*)$/D', $id, $parts)) {
                return null;
            }
            $row = DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->where('id', $parts[1])
                ->where('status', 'completed')->where('closed_at', '>=', now()->subDay())->first();
            return $row ? $this->item($kind, (string) $row->id, (string) $row->business_date, false) : null;
        }
        foreach ($this->active($hotel) as $alert) {
            if ($alert['id'] === $id) {
                return $alert;
            }
        }

        return null;
    }
}

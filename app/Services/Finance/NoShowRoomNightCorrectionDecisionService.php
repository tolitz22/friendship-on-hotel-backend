<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\NoShowWorkflow;
use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;

/** Approval records intent only. Never posts or reverses accounting entries. */
final class NoShowRoomNightCorrectionDecisionService
{
    public function propose(Hotel $hotel, int $bookingId, int $actorId, string $reason): int
    {
        $reason = $this->reason($reason);
        return DB::transaction(function () use ($hotel, $bookingId, $actorId, $reason): int {
            $this->authorize($hotel, $actorId);
            $snapshot = $this->snapshot($hotel, $bookingId);
            abort_if(DB::table('no_show_room_correction_proposals')->where('hotel_id', $hotel->id)
                ->where('booking_id', $bookingId)->exists(), 409, 'A correction proposal already exists.');
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
            return (int) DB::table('no_show_room_correction_proposals')->insertGetId([
                'hotel_id' => $hotel->id, 'booking_id' => $bookingId, 'proposed_by' => $actorId,
                'business_date' => $snapshot['businessDate'], 'reason' => $reason,
                'snapshot' => $json, 'snapshot_sha256' => hash('sha256', $json), 'created_at' => now(),
            ]);
        }, 3);
    }

    public function approve(Hotel $hotel, int $proposalId, int $actorId, string $reason): int
    {
        $reason = $this->reason($reason);
        return DB::transaction(function () use ($hotel, $proposalId, $actorId, $reason): int {
            $this->authorize($hotel, $actorId);
            $proposal = DB::table('no_show_room_correction_proposals')->where('hotel_id', $hotel->id)
                ->where('id', $proposalId)->lockForUpdate()->first();
            abort_unless($proposal !== null, 404);
            abort_if((int) $proposal->proposed_by === $actorId, 403, 'Independent administrator required.');
            abort_if(DB::table('no_show_room_correction_reviews')->where('proposal_id', $proposalId)->exists(),
                409, 'Correction already approved.');
            $snapshot = $this->snapshot($hotel, (int) $proposal->booking_id);
            abort_unless((string) $proposal->business_date === $snapshot['businessDate']
                && hash_equals($proposal->snapshot_sha256, hash('sha256', $proposal->snapshot))
                && hash_equals($proposal->snapshot_sha256, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))),
                409, 'Accounting evidence changed; do not approve this proposal.');
            return (int) DB::table('no_show_room_correction_reviews')->insertGetId([
                'proposal_id' => $proposalId, 'reviewed_by' => $actorId, 'reason' => $reason, 'created_at' => now(),
            ]);
        }, 3);
    }


    /**
     * Reverse the complete approved journal set atomically. Revenue recognition
     * must be reversed before VAT and the original deferred source; never
     * leave an independently posted partial correction.
     */
    public function post(Hotel $hotel, int $proposalId, int $actorId): array
    {
        abort_unless(config('financial_accounting.enable_no_show_room_correction_posting', false),
            409, 'Room-night correction posting is disabled.');

        return DB::transaction(function () use ($hotel, $proposalId, $actorId): array {
            $this->authorize($hotel, $actorId);
            $proposal = DB::table('no_show_room_correction_proposals')->where('hotel_id', $hotel->id)
                ->where('id', $proposalId)->lockForUpdate()->first();
            abort_unless($proposal !== null, 404);
            $approval = DB::table('no_show_room_correction_reviews')->where('proposal_id', $proposalId)->first();
            abort_unless($approval !== null && (int) $approval->reviewed_by === $actorId
                && (int) $proposal->proposed_by !== $actorId, 403, 'Independent approving administrator required.');
            abort_unless(hash_equals($proposal->snapshot_sha256, hash('sha256', $proposal->snapshot)),
                409, 'Invalid approved snapshot.');
            $approved = json_decode($proposal->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            abort_unless($clock !== null && $clock->status === 'open', 409, 'Open business date required.');
            $originals = $approved['journalRecords'];
            abort_unless($originals !== [], 409, 'Approved journal set is empty.');
            $order = ['nightly_revenue' => 0, 'vat_liability' => 1, 'folio_benefit' => 2, 'folio_deferred' => 3];
            foreach ($originals as $original) {
                abort_unless(isset($order[$original['source_type']]), 409, 'Unsupported original journal.');
            }
            usort($originals, fn (array $a, array $b): int =>
                [$order[$a['source_type']], (int) $a['id']] <=> [$order[$b['source_type']], (int) $b['id']]);

            $existing = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                ->where('source_type', 'no_show_room_correction')->where('source_reference', 'proposal-'.$proposalId)
                ->get()->keyBy('reverses_journal_id');
            $replayed = $existing->isNotEmpty();
            if ($replayed) {
                abort_unless($existing->count() === count($originals), 409, 'Incomplete reversal set; manual review required.');
            } else {
                $snapshot = $this->snapshot($hotel, (int) $proposal->booking_id);
                abort_unless((string) $proposal->business_date === $snapshot['businessDate']
                    && hash_equals($proposal->snapshot_sha256, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))),
                    409, 'Approved accounting evidence changed; posting blocked.');
            }

            $ids = [];
            foreach ($originals as $original) {
                $originalId = (int) $original['id'];
                $source = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('id', $originalId)->first();
                abort_unless($source !== null && $source->currency === 'PHP' && $source->source_type === $original['source_type']
                    && $source->source_reference === $original['source_reference'] && $source->reverses_journal_id === null,
                    409, 'Original journal changed.');
                $lines = [];
                foreach ($approved['journalLines'] as $line) {
                    if ((int) $line['journal_id'] === $originalId) {
                        $lines[] = ['account_code' => $line['account_code'], 'debit_minor' => (int) $line['credit_minor'],
                            'credit_minor' => (int) $line['debit_minor']];
                    }
                }
                $current = DB::table('financial_posting_lines')->where('journal_id', $originalId)->orderBy('line_number')->get()
                    ->map(fn ($line): array => ['account_code' => $line->account_code,
                        'debit_minor' => (int) $line->credit_minor, 'credit_minor' => (int) $line->debit_minor])->all();
                abort_unless(count($lines) >= 2 && $lines === $current, 409, 'Original journal lines changed.');
                $key = 'no-show-room-reversal-'.$proposalId.'-'.$originalId;
                if ($replayed) {
                    $prior = $existing->get($originalId);
                    abort_unless($prior !== null && $prior->idempotency_key === $key && $prior->currency === 'PHP'
                        && (int) $prior->posted_by === $actorId
                        && !DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
                            ->where('reverses_journal_id', $prior->id)->exists(), 409, 'Invalid prior correction journal.');
                }
                $ids[] = app(FinancialJournalPostingService::class)->post(
                    (int) $hotel->id, $key, 'no_show_room_correction', 'proposal-'.$proposalId,
                    'Approved no-show room accounting reversal #'.$originalId, $lines, $actorId, $originalId
                );
            }
            return ['proposalId' => (string) $proposalId, 'journalIds' => array_map('strval', $ids),
                'replayed' => $replayed, 'folioAdjusted' => false, 'refundIssued' => false];
        }, 3);
    }

    private function authorize(Hotel $hotel, int $actorId): void
    {
        abort_unless(config('financial_accounting.enable_no_show_room_correction_review', false)
            && NoShowWorkflow::enabled((int) $hotel->id), 409, 'Room-night correction review is disabled.');
        app(ReadinessWriteGuard::class)->lockHotel($hotel);
        abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
            ->where('user_id', $actorId)->where('role', 'admin')->where('is_active', true)->exists()
            && DB::table('users')->where('id', $actorId)->where('is_active', true)->exists(), 403);
    }

    private function snapshot(Hotel $hotel, int $bookingId): array
    {
        $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
        abort_unless($clock !== null && $clock->status === 'open'
            && !DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->where('business_date', $clock->business_date)->exists(),
            409, 'Open business date required.');
        $preview = app(NoShowRoomNightCorrectionPreview::class)->preview($hotel, $bookingId);
        abort_unless($preview['currency'] === 'PHP' && $preview['requiresCorrection'] && $preview['blockers'] === [],
            409, 'Unresolved folio accounting blockers require separate review.');
        $folioId = DB::table('reservation_folios')->where('hotel_id', $hotel->id)->where('reservation_record_id', $bookingId)->value('id');
        abort_if(DB::table('folio_payment_records')->where('hotel_id', $hotel->id)->where('folio_id', $folioId)->exists(),
            409, 'Existing payment evidence requires separate reconciliation.');
        $journalIds = [];
        foreach ($preview['nights'] as $night) {
            abort_unless($night['status'] === 'approved' && $night['issues'] === [] && $night['journals'] !== [],
                409, 'Only fully matched original accounting is eligible for this correction review.');
            foreach ($night['journals'] as $journal) {
                abort_unless($journal['matchesAssessment'] && !$journal['reversed']
                    && !in_array($journal['id'], $journalIds, true), 409, 'Invalid or duplicate journal reference.');
                $journalIds[] = $journal['id'];
            }
        }
        // Freeze raw journal lines and identities in addition to the derived preview.
        $preview['journalRecords'] = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
            ->whereIn('id', $journalIds)->orderBy('id')->get()->all();
        $preview['journalLines'] = DB::table('financial_posting_lines')->whereIn('journal_id', $journalIds)
            ->orderBy('journal_id')->orderBy('line_number')->get()->all();
        $preview['assessments'] = DB::table('financial_folio_night_assessments')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folioId)->orderBy('id')->get()->all();
        $preview['folioEntries'] = DB::table('folio_entries')->where('hotel_id', $hotel->id)
            ->where('folio_id', $folioId)->orderBy('id')->get()->all();
        return $preview;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        abort_unless(mb_strlen($reason) >= 15 && mb_strlen($reason) <= 1000, 422, 'Document the correction reason.');
        return $reason;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Finance\FinancialBusinessDayCloseService;
use App\Services\Finance\FinancialJournalPostingService;
use App\Services\Finance\NightlyRoomRevenueRecognitionService;
use App\Services\Finance\FinancialTaxPolicyService;
use App\Http\Controllers\Api\FinancialBusinessDayController;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Isolated financial schema tests: no production data, payment provider or
 * hotel authentication is needed to verify the posting/close transaction.
 */
final class FinancialBusinessDayIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('night_audit_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->date('business_date');
            $table->string('status');
            $table->unsignedBigInteger('reviewed_by');
            $table->timestamp('reviewed_at');
            $table->unique(['hotel_id', 'business_date']);
        });
        Schema::create('financial_business_clocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id')->unique();
            $table->date('business_date');
            $table->string('status');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('financial_accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('financial_day_closes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->date('business_date');
            $table->string('status');
            $table->unsignedBigInteger('closed_by');
            $table->timestamp('closed_at');
            $table->text('reconciliation_snapshot');
            $table->timestamps();
            $table->unique(['hotel_id', 'business_date']);
        });
        Schema::create('financial_posting_journals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('accounting_period_id');
            $table->date('business_date');
            $table->string('source_type');
            $table->string('source_reference');
            $table->string('idempotency_key');
            $table->unsignedBigInteger('reverses_journal_id')->nullable();
            $table->string('currency');
            $table->string('description');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['hotel_id', 'idempotency_key']);
        });
        Schema::create('financial_tax_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('classification');
            $table->string('price_mode');
            $table->unsignedInteger('rate_bps');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });
        Schema::create('financial_room_revenue_recognitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('source_journal_id');
            $table->unsignedBigInteger('recognition_journal_id')->unique();
            $table->date('stay_date');
            $table->bigInteger('amount_minor');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'source_journal_id', 'stay_date'], 'room_rev_once_per_source_night');
        });
        Schema::create('financial_posting_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedInteger('line_number');
            $table->string('account_code');
            $table->bigInteger('debit_minor');
            $table->bigInteger('credit_minor');
            $table->string('description')->nullable();
            $table->unique(['journal_id', 'line_number']);
        });
    }

    protected function tearDown(): void
    {
        foreach (['financial_tax_policies', 'financial_room_revenue_recognitions', 'night_audit_reviews', 'financial_posting_lines', 'financial_posting_journals', 'financial_day_closes',
            'financial_accounting_periods', 'financial_business_clocks'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function seedHotel(int $hotelId, string $date = '2026-11-18'): void
    {
        DB::table('financial_business_clocks')->insert([
            'hotel_id' => $hotelId, 'business_date' => $date, 'status' => 'open', 'version' => 0,
        ]);
        DB::table('financial_accounting_periods')->insert([
            'hotel_id' => $hotelId, 'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30', 'status' => 'open',
        ]);
    }

    private function postJournal(int $hotelId, string $key = 'charge-1'): int
    {
        return (new FinancialJournalPostingService())->post(
            $hotelId, $key, 'folio', $key, 'Room charge',
            [
                ['account_code' => 'AR', 'debit_minor' => 12500, 'credit_minor' => 0],
                ['account_code' => 'ROOM_REV', 'debit_minor' => 0, 'credit_minor' => 12500],
            ],
        );
    }

    public function test_close_rolls_date_and_persists_a_balanced_snapshot(): void
    {
        $this->seedHotel(1);
        $journalId = $this->postJournal(1);

        $result = (new FinancialBusinessDayCloseService())->close(1, '2026-11-18', 7);

        self::assertSame('2026-11-19', $result['next_business_date']);
        self::assertSame(1, $result['snapshot']['journal_count']);
        self::assertSame(2, $result['snapshot']['line_count']);
        self::assertSame(12500, $result['snapshot']['debits_minor']);
        self::assertSame(12500, $result['snapshot']['credits_minor']);
        self::assertSame('2026-11-19', DB::table('financial_business_clocks')->where('hotel_id', 1)->value('business_date'));
        self::assertSame(1, (int) DB::table('financial_business_clocks')->where('hotel_id', 1)->value('version'));
        self::assertSame('2026-11-18', DB::table('financial_posting_journals')->where('id', $journalId)->value('business_date'));

        // A repeated delivery of the same posting key is safe after day close.
        self::assertSame($journalId, $this->postJournal(1));
        self::assertSame(1, DB::table('financial_posting_journals')->count());
        $this->postJournal(1, 'charge-2');
        self::assertSame('2026-11-19', DB::table('financial_posting_journals')->where('id', '!=', $journalId)->value('business_date'));
    }

    public function test_failed_reconciliation_rolls_back_close_and_clock(): void
    {
        $this->seedHotel(1);
        $journalId = $this->postJournal(1);
        DB::table('financial_posting_lines')->where('journal_id', $journalId)
            ->where('account_code', 'ROOM_REV')->update(['credit_minor' => 12499]);

        try {
            (new FinancialBusinessDayCloseService())->close(1, '2026-11-18', 7);
            self::fail('Unbalanced journals must prevent day close.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('journals', $exception->errors());
        }
        self::assertSame(0, DB::table('financial_day_closes')->count());
        self::assertSame('2026-11-18', DB::table('financial_business_clocks')->where('hotel_id', 1)->value('business_date'));
        self::assertSame(0, (int) DB::table('financial_business_clocks')->where('hotel_id', 1)->value('version'));
    }

    public function test_missing_next_day_period_prevents_partial_close(): void
    {
        $this->seedHotel(1);
        DB::table('financial_accounting_periods')->where('hotel_id', 1)
            ->update(['ends_on' => '2026-11-18']);

        try {
            (new FinancialBusinessDayCloseService())->close(1, '2026-11-18', 7);
            self::fail('A day close cannot roll into an uncovered accounting date.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('accounting_period', $exception->errors());
        }
        self::assertSame(0, DB::table('financial_day_closes')->count());
        self::assertSame('2026-11-18', DB::table('financial_business_clocks')->where('hotel_id', 1)->value('business_date'));
    }

    public function test_duplicate_close_does_not_advance_clock_twice(): void
    {
        $this->seedHotel(1);
        $service = new FinancialBusinessDayCloseService();
        $service->close(1, '2026-11-18', 7);

        try {
            $service->close(1, '2026-11-18', 7);
            self::fail('A completed business date cannot be closed twice.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('business_date', $exception->errors());
        }
        self::assertSame('2026-11-19', DB::table('financial_business_clocks')->where('hotel_id', 1)->value('business_date'));
        self::assertSame(1, DB::table('financial_day_closes')->count());
    }

    public function test_closed_day_cannot_be_reopened_by_restoring_clock_and_hotels_are_isolated(): void
    {
        $this->seedHotel(1);
        $this->seedHotel(2);
        (new FinancialBusinessDayCloseService())->close(1, '2026-11-18', 7);

        // Simulate an out-of-band clock restore. The close ledger remains authoritative.
        DB::table('financial_business_clocks')->where('hotel_id', 1)->update([
            'business_date' => '2026-11-18',
        ]);
        try {
            $this->postJournal(1, 'late-post');
            self::fail('A closed financial date must stay immutable.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('business_date', $exception->errors());
        }

        self::assertSame(0, DB::table('financial_posting_journals')->where('hotel_id', 1)->count());
        self::assertSame(1, DB::table('financial_day_closes')->where('hotel_id', 1)->count());
        $this->postJournal(2, 'independent-post');
        self::assertSame('2026-11-18', DB::table('financial_business_clocks')->where('hotel_id', 2)->value('business_date'));
        self::assertSame(1, DB::table('financial_posting_journals')->where('hotel_id', 2)->count());
    }
    public function test_replaying_a_posting_key_with_a_different_payload_is_rejected(): void
    {
        $this->seedHotel(1);
        $original = $this->postJournal(1, 'same-key');

        try {
            (new FinancialJournalPostingService())->post(
                1, 'same-key', 'folio', 'same-key', 'Room charge',
                [
                    ['account_code' => 'AR', 'debit_minor' => 13000, 'credit_minor' => 0],
                    ['account_code' => 'ROOM_REV', 'debit_minor' => 0, 'credit_minor' => 13000],
                ],
            );
            self::fail('Idempotency keys must reject conflicting amounts.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('idempotency_key', $exception->errors());
        }

        self::assertSame(1, DB::table('financial_posting_journals')->count());
        self::assertSame(12500, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $original)->where('account_code', 'AR')->value('debit_minor'));
    }

    public function test_reversal_cannot_target_another_hotel_journal(): void
    {
        $this->seedHotel(1);
        $this->seedHotel(2);
        $original = $this->postJournal(1, 'original');

        try {
            (new FinancialJournalPostingService())->post(
                2, 'foreign-reversal', 'folio', 'foreign-reversal', 'Reverse charge',
                [
                    ['account_code' => 'AR', 'debit_minor' => 0, 'credit_minor' => 12500],
                    ['account_code' => 'ROOM_REV', 'debit_minor' => 12500, 'credit_minor' => 0],
                ],
                null,
                $original,
            );
            self::fail('A journal from another hotel cannot be reversed.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reverses_journal_id', $exception->errors());
        }

        self::assertSame(0, DB::table('financial_posting_journals')->where('hotel_id', 2)->count());
        self::assertSame(1, DB::table('financial_posting_journals')->where('hotel_id', 1)->count());
    }

    public function test_readiness_explains_period_and_journal_blockers(): void
    {
        $this->seedHotel(1);
        $hotel = new Hotel();
        $hotel->id = 1;
        $controller = new FinancialBusinessDayController();

        $initial = $controller->show($hotel)->getData(true)['data'];
        self::assertFalse($initial['canClose']);
        self::assertNotEmpty($initial['blockers']);
        self::assertTrue($initial['readinessIsAdvisory']);

        DB::table('night_audit_reviews')->insert([
            'hotel_id' => 1, 'business_date' => '2026-11-18', 'status' => 'reviewed',
            'reviewed_by' => 7, 'reviewed_at' => now(),
        ]);
        self::assertTrue($controller->show($hotel)->getData(true)['data']['canClose']);

        DB::table('financial_accounting_periods')->where('hotel_id', 1)
            ->update(['ends_on' => '2026-11-18']);
        $missingPeriod = $controller->show($hotel)->getData(true)['data'];
        self::assertFalse($missingPeriod['canClose']);
        self::assertTrue(collect($missingPeriod['blockers'])
            ->contains(fn (string $reason) => str_contains($reason, 'Next business date')));

        DB::table('financial_accounting_periods')->where('hotel_id', 1)
            ->update(['ends_on' => '2026-11-30']);
        $id = $this->postJournal(1);
        DB::table('financial_posting_lines')->where('journal_id', $id)
            ->where('account_code', 'ROOM_REV')->update(['credit_minor' => 12499]);
        $unbalanced = $controller->show($hotel)->getData(true)['data'];
        self::assertFalse($unbalanced['canClose']);
        self::assertSame(1, $unbalanced['invalidJournalCount']);
        self::assertSame(1, $unbalanced['journalCount']);
        self::assertSame('2026-11-18', $unbalanced['businessDate']);
    }

    public function test_admin_close_endpoint_requires_cleared_night_audit_review(): void
    {
        $this->seedHotel(1);
        $hotel = new Hotel();
        $hotel->id = 1;
        $controller = new FinancialBusinessDayController();
        $service = new FinancialBusinessDayCloseService();
        $request = Request::create('/api/v1/hotels/1/finance/business-day/close', 'POST', [
            'businessDate' => '2026-11-18',
            'acknowledged' => true,
        ]);
        $request->setUserResolver(static fn () => (object) ['id' => 7]);

        self::assertFalse($controller->show($hotel)->getData(true)['data']['canClose']);

        try {
            $controller->close($request, $hotel, $service);
            self::fail('Missing review must block close.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('businessDate', $exception->errors());
        }

        DB::table('night_audit_reviews')->insert([
            'hotel_id' => 1, 'business_date' => '2026-11-18', 'status' => 'flagged',
            'reviewed_by' => 7, 'reviewed_at' => now(),
        ]);
        try {
            $controller->close($request, $hotel, $service);
            self::fail('Flagged review must block close.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('businessDate', $exception->errors());
        }

        DB::table('night_audit_reviews')->where('hotel_id', 1)->update(['status' => 'reviewed']);
        self::assertTrue($controller->show($hotel)->getData(true)['data']['canClose']);

        $result = $controller->close($request, $hotel, $service)->getData(true)['data'];
        self::assertSame('2026-11-19', $result['nextBusinessDate']);
        self::assertSame(1, DB::table('financial_day_closes')->where('hotel_id', 1)->count());
        self::assertFalse($controller->show($hotel)->getData(true)['data']['canClose']);
    }

    public function test_deferred_revenue_recognition_is_idempotent_and_never_charges_folio(): void
    {
        $this->seedHotel(1);
        $source = (new FinancialJournalPostingService())->post(
            1, 'defer-first', 'folio_deferred', 'booking-42', 'Deferred reservation revenue',
            [
                ['account_code' => 'AR', 'debit_minor' => 11200, 'credit_minor' => 0],
                ['account_code' => 'DEFERRED_ROOM_REV', 'debit_minor' => 0, 'credit_minor' => 11200],
            ],
        );
        $service = new NightlyRoomRevenueRecognitionService();
        $earned = $service->recognize(1, $source, '2026-11-18', 10000, 7);
        self::assertSame($earned, $service->recognize(1, $source, '2026-11-18', 10000, 7));
        self::assertSame(1, DB::table('financial_room_revenue_recognitions')->count());
        self::assertSame(2, DB::table('financial_posting_journals')->count());
        self::assertSame('nightly_revenue',
            DB::table('financial_posting_journals')->where('id', $earned)->value('source_type'));
        self::assertSame(10000, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $earned)->where('account_code', 'ROOM_REV')->value('credit_minor'));

        try {
            $service->recognize(1, $source, '2026-11-18', 11000, 7);
            self::fail('Recognition replay cannot change the amount.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amount_minor', $exception->errors());
        }
    }

    public function test_existing_revenue_journals_cannot_be_recognized_twice(): void
    {
        $this->seedHotel(1);
        $alreadyEarned = $this->postJournal(1);
        try {
            (new NightlyRoomRevenueRecognitionService())
                ->recognize(1, $alreadyEarned, '2026-11-18', 12500, 7);
            self::fail('Existing ROOM_REV credits cannot be recognized twice.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('source_journal_id', $exception->errors());
        }
        self::assertSame(0, DB::table('financial_room_revenue_recognitions')->count());
        self::assertSame(1, DB::table('financial_posting_journals')->count());
    }

    public function test_recognition_requires_current_open_date_and_hotel_isolation(): void
    {
        $this->seedHotel(1);
        $this->seedHotel(2);
        $source = (new FinancialJournalPostingService())->post(
            1, 'defer-source', 'folio_deferred', 'booking-99', 'Deferred reservation revenue',
            [
                ['account_code' => 'AR', 'debit_minor' => 1000, 'credit_minor' => 0],
                ['account_code' => 'DEFERRED_ROOM_REV', 'debit_minor' => 0, 'credit_minor' => 1000],
            ],
        );
        $service = new NightlyRoomRevenueRecognitionService();
        try {
            $service->recognize(2, $source, '2026-11-18', 500);
            self::fail('A different hotel cannot recognize the source.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('source_journal_id', $exception->errors());
        }
        try {
            $service->recognize(1, $source, '2026-11-19', 500);
            self::fail('A future stay date cannot be recognized.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('stay_date', $exception->errors());
        }
        $service->recognize(1, $source, '2026-11-18', 500);
        self::assertSame(1, DB::table('financial_room_revenue_recognitions')->count());
        self::assertSame(0, DB::table('financial_posting_journals')->where('hotel_id', 2)->count());
    }

    public function test_tax_policy_approval_requires_unique_effective_window(): void
    {
        $this->seedHotel(1);
        $service = new FinancialTaxPolicyService();
        $first = $service->draft(1, 'vat_standard', 'inclusive', 1200,
            '2026-11-18', '2026-11-30', 'Hotel tax treatment reviewed by finance.');
        try {
            $service->preview(1, '2026-11-18', 11200);
            self::fail('Draft tax policy must not be used in financial previews.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('tax_policy', $exception->errors());
        }

        $service->approve(1, $first, 7);
        $result = $service->preview(1, '2026-11-18', 11200);
        self::assertTrue($result['preview_only']);
        self::assertFalse($result['folio_updated']);
        self::assertFalse($result['journal_posted']);
        self::assertSame(1200, $result['breakdown']['tax_minor']);
        self::assertSame(10000, $result['breakdown']['base_minor']);

        $overlap = $service->draft(1, 'vat_exempt', 'inclusive', 0,
            '2026-11-25', null, 'Review of an alternate tax treatment.');
        try {
            $service->approve(1, $overlap, 7);
            self::fail('Overlapping approved tax configurations must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('effective_from', $exception->errors());
        }
        self::assertSame('draft', DB::table('financial_tax_policies')->where('id', $overlap)->value('status'));
    }

    public function test_tax_previews_are_hotel_scoped_and_drafts_are_not_live(): void
    {
        $this->seedHotel(1);
        $this->seedHotel(2);
        $service = new FinancialTaxPolicyService();
        $policy = $service->draft(1, 'vat_zero', 'inclusive', 0,
            '2026-11-18', null, 'Finance approved this classification for testing.');
        $service->approve(1, $policy, 7);
        self::assertSame(0, $service->preview(1, '2026-11-18', 10000)['breakdown']['tax_minor']);
        try {
            $service->preview(2, '2026-11-18', 10000);
            self::fail('Other hotels must not inherit approved tax policies.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('tax_policy', $exception->errors());
        }
        self::assertSame(0, DB::table('financial_posting_journals')->count());
    }

    public function test_reversal_is_append_only_and_does_not_duplicate_recognition(): void
    {
        $this->seedHotel(1);
        $posting = new FinancialJournalPostingService();
        $source = $posting->post(1, 'defer-reversal', 'folio_deferred', 'booking-reversal',
            'Deferred room revenue', [
                ['account_code' => 'AR', 'debit_minor' => 10000, 'credit_minor' => 0],
                ['account_code' => 'DEFERRED_ROOM_REV', 'debit_minor' => 0, 'credit_minor' => 10000],
            ]);
        $service = new NightlyRoomRevenueRecognitionService();
        $earned = $service->recognize(1, $source, '2026-11-18', 10000, 7);
        $reversal = $service->reverse(1, $earned, 7, 'Correct room revenue classification');
        self::assertSame($reversal,
            $service->reverse(1, $earned, 7, 'Correct room revenue classification'));
        self::assertSame(3, DB::table('financial_posting_journals')->count());
        self::assertSame($earned, (int) DB::table('financial_posting_journals')
            ->where('id', $reversal)->value('reverses_journal_id'));
        self::assertSame(10000, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $reversal)->where('account_code', 'ROOM_REV')->value('debit_minor'));
        self::assertSame(1, DB::table('financial_room_revenue_recognitions')->count());
    }

}

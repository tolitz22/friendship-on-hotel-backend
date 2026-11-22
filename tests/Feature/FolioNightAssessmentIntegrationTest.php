<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Finance\FolioNightAssessmentService;
use App\Services\Finance\FinancialBusinessDayCloseService;
use App\Services\Finance\FolioNightCorrectionService;
use App\Services\Finance\FinancialJournalPostingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class FolioNightAssessmentIntegrationTest extends TestCase
{
    private const TABLES = [
        'financial_room_revenue_recognitions',
        'financial_folio_night_corrections',
        'financial_folio_night_assessments',
        'folio_payment_records',
        'folio_adjustments',
        'folio_entries',
        'reservation_folios',
        'reservation_records',
        'financial_posting_lines',
        'financial_posting_journals',
        'financial_tax_policies',
        'financial_day_closes',
        'financial_accounting_periods',
        'financial_business_clocks',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('hotels')) {
            Schema::create('hotels', function (Blueprint $table): void {
                $table->id();
            });
        }
        if (!Schema::hasTable('cashier_shifts')) {
            Schema::create('cashier_shifts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('hotel_id');
                $table->string('status');
            });
        }
        config()->set('financial_accounting.enable_live_folio_tax', true);

        Schema::create('financial_business_clocks', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id')->unique();
            $t->date('business_date');$t->string('status');$t->timestamps();
        });
        Schema::create('financial_accounting_periods', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->date('starts_on');
            $t->date('ends_on');$t->string('status');
        });
        Schema::create('financial_day_closes', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->date('business_date');
        });
        Schema::create('financial_posting_journals', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('accounting_period_id');
            $t->date('business_date');$t->string('source_type');$t->string('source_reference');
            $t->string('idempotency_key');$t->unsignedBigInteger('reverses_journal_id')->nullable();
            $t->string('currency');$t->string('description');$t->unsignedBigInteger('posted_by')->nullable();
            $t->timestamp('posted_at');$t->timestamps();
            $t->unique(['hotel_id', 'idempotency_key']);
        });
        Schema::create('financial_posting_lines', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('journal_id');$t->unsignedInteger('line_number');
            $t->string('account_code');$t->bigInteger('debit_minor');
            $t->bigInteger('credit_minor');$t->string('description')->nullable();
        });
        Schema::create('financial_tax_policies', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->string('classification');
            $t->string('price_mode');$t->integer('rate_bps');
            $t->date('effective_from');$t->date('effective_until')->nullable();
            $t->string('status');
        });
        Schema::create('reservation_records', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->string('lifecycle_state');
            $t->date('check_in');$t->date('check_out');
        });
        Schema::create('reservation_folios', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('reservation_record_id');
            $t->string('currency');$t->timestamps();
        });
        Schema::create('folio_entries', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->string('entry_type');$t->bigInteger('amount_minor');$t->date('stay_date');
        });
        Schema::create('folio_adjustments', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->string('request_key');$t->string('payload_sha256');
            $t->string('kind');$t->bigInteger('amount_minor');$t->string('reason');
            $t->unsignedBigInteger('actor_id');$t->timestamps();
        });
        Schema::create('folio_payment_records', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
        });
        Schema::create('financial_folio_night_assessments', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('reservation_record_id');
            $t->unsignedBigInteger('folio_id');$t->unsignedBigInteger('folio_entry_id');
            $t->unsignedBigInteger('tax_policy_id');$t->date('stay_date');$t->bigInteger('gross_minor');
            $t->bigInteger('eligible_gross_minor');$t->string('benefit_kind');
            $t->string('evidence_reference')->nullable();$t->char('payload_sha256', 64);
            $t->bigInteger('folio_credit_minor');$t->bigInteger('vat_minor');
            $t->bigInteger('room_revenue_minor');$t->bigInteger('payable_minor');
            $t->string('status');$t->unsignedBigInteger('prepared_by');
            $t->unsignedBigInteger('reviewed_by')->nullable();$t->timestamp('reviewed_at')->nullable();
            $t->unsignedBigInteger('source_journal_id')->nullable();
            $t->unsignedBigInteger('benefit_journal_id')->nullable();
            $t->unsignedBigInteger('vat_journal_id')->nullable();$t->timestamps();
            $t->unique(['hotel_id', 'folio_entry_id']);
        });
        Schema::create('financial_folio_night_corrections', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('assessment_id');
            $t->string('benefit_kind');$t->bigInteger('eligible_gross_minor');
            $t->string('evidence_reference')->nullable();
            $t->bigInteger('folio_credit_minor');$t->bigInteger('vat_minor');
            $t->bigInteger('room_revenue_minor');$t->bigInteger('payable_minor');
            $t->string('reason');$t->string('status');
            $t->unsignedBigInteger('prepared_by');$t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('folio_adjustment_id')->nullable();$t->timestamps();
        });
        Schema::create('financial_room_revenue_recognitions', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('source_journal_id');
            $t->unsignedBigInteger('recognition_journal_id');
            $t->date('stay_date');$t->bigInteger('amount_minor');$t->timestamp('created_at');
            $t->unique(['hotel_id', 'source_journal_id', 'stay_date'], 'room_rev_source_night_once');
        });

        $this->seedFolio();
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function seedFolio(): void
    {
        DB::table('financial_business_clocks')->insert([
            'hotel_id' => 1, 'business_date' => '2026-11-18', 'status' => 'open',
        ]);
        DB::table('financial_accounting_periods')->insert([
            'hotel_id' => 1, 'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30', 'status' => 'open',
        ]);
        DB::table('financial_tax_policies')->insert([
            'id' => 9, 'hotel_id' => 1, 'classification' => 'vat_standard',
            'price_mode' => 'inclusive', 'rate_bps' => 1200,
            'effective_from' => '2026-11-18', 'effective_until' => null, 'status' => 'approved',
        ]);
        DB::table('reservation_records')->insert([
            'id' => 11, 'hotel_id' => 1, 'lifecycle_state' => 'confirmed',
            'check_in' => '2026-11-18', 'check_out' => '2026-11-19',
        ]);
        DB::table('reservation_folios')->insert([
            'id' => 12, 'hotel_id' => 1, 'reservation_record_id' => 11, 'currency' => 'PHP',
        ]);
        DB::table('folio_entries')->insert([
            'id' => 13, 'hotel_id' => 1, 'folio_id' => 12,
            'entry_type' => 'room_night', 'amount_minor' => 11200, 'stay_date' => '2026-11-18',
        ]);
    }

    public function test_review_posts_vat_and_recognizes_actual_folio_night_once(): void
    {
        $service = new FolioNightAssessmentService();
        $pending = $service->prepare(1, 13, 9, 7);
        self::assertSame('pending', $pending['status']);
        self::assertSame(11200, $pending['grossMinor']);
        self::assertSame(1200, $pending['vatMinor']);
        self::assertSame(10000, $pending['roomRevenueMinor']);

        try {
            $service->approve(1, (int) $pending['id'], 7);
            self::fail('The preparer may not approve their own assessment.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reviewer', $exception->errors());
        }

        $approved = $service->approve(1, (int) $pending['id'], 8);
        self::assertSame('approved', $approved['status']);
        self::assertSame(2, DB::table('financial_posting_journals')->count());
        self::assertSame(1200, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $approved['vatJournalId'])->where('account_code', 'VAT_PAYABLE')
            ->value('credit_minor'));
        self::assertSame(1, DB::table('folio_entries')->count());
        self::assertSame(0, DB::table('folio_adjustments')->count());
        $earned = $service->recognize(1, (int) $pending['id'], 8);
        self::assertSame($earned, $service->recognize(1, (int) $pending['id'], 8));
        self::assertSame(3, DB::table('financial_posting_journals')->count());
        self::assertSame(10000, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $earned)->where('account_code', 'ROOM_REV')
            ->value('credit_minor'));
        self::assertSame(1, DB::table('folio_entries')->count());
    }

    public function test_qualified_senior_benefit_credits_existing_folio_only_once(): void
    {
        $service = new FolioNightAssessmentService();
        $pending = $service->prepare(1, 13, 9, 7, 'senior', 11200, 'verified-osca-reference');
        self::assertSame(3200, $pending['folioCreditMinor']);
        self::assertSame(0, $pending['vatMinor']);
        self::assertSame(8000, $pending['roomRevenueMinor']);
        $approved = $service->approve(1, (int) $pending['id'], 8);
        self::assertSame('approved', $approved['status']);
        self::assertSame(3200, (int) DB::table('folio_adjustments')->value('amount_minor'));
        self::assertSame('credit', DB::table('folio_adjustments')->value('kind'));
        self::assertSame(1, DB::table('folio_entries')->count());
        self::assertSame(2, DB::table('financial_posting_journals')->count());

        try {
            $service->approve(1, (int) $pending['id'], 8);
            self::fail('Approval must not be performed twice.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('assessment', $exception->errors());
        }
        self::assertSame(1, DB::table('folio_adjustments')->count());
    }

    public function test_existing_payment_evidence_blocks_statutory_benefit_credit(): void
    {
        $service = new FolioNightAssessmentService();
        $pending = $service->prepare(1, 13, 9, 7, 'pwd', 11200, 'verified-pwd-reference');
        DB::table('folio_payment_records')->insert(['hotel_id' => 1, 'folio_id' => 12]);
        try {
            $service->approve(1, (int) $pending['id'], 8);
            self::fail('Existing payment evidence must require separate refund reconciliation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('benefit_kind', $exception->errors());
        }
        self::assertSame(0, DB::table('financial_posting_journals')->count());
        self::assertSame(0, DB::table('folio_adjustments')->count());
        self::assertSame('pending', DB::table('financial_folio_night_assessments')->value('status'));
    }

    public function test_unapproved_policy_and_duplicate_payload_are_rejected(): void
    {
        $service = new FolioNightAssessmentService();
        DB::table('financial_tax_policies')->where('id', 9)->update(['status' => 'draft']);
        try {
            $service->prepare(1, 13, 9, 7);
            self::fail('Draft tax policy must not be used for folio assessment.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('tax_policy_id', $exception->errors());
        }
        DB::table('financial_tax_policies')->where('id', 9)->update(['status' => 'approved']);
        $first = $service->prepare(1, 13, 9, 7);
        self::assertSame($first['id'], $service->prepare(1, 13, 9, 7)['id']);
        try {
            $service->prepare(1, 13, 9, 7, 'pwd', 11200, 'different-evidence');
            self::fail('A different assessment must not replace existing folio entry.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('folio_entry_id', $exception->errors());
        }
        self::assertSame(1, DB::table('financial_folio_night_assessments')->count());
    }

    public function test_recognition_rejects_amount_greater_than_reviewed_room_revenue(): void
    {
        $service = new FolioNightAssessmentService();
        $draft = $service->prepare(1, 13, 9, 7);
        $approved = $service->approve(1, (int) $draft['id'], 8);
        try {
            (new \App\Services\Finance\NightlyRoomRevenueRecognitionService())->recognize(
                1, (int) $approved['sourceJournalId'], '2026-11-18', 11200, 8
            );
            self::fail('VAT cannot be recognized again as room revenue.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amount_minor', $exception->errors());
        }
        self::assertSame(0, DB::table('financial_room_revenue_recognitions')->count());
    }
    public function test_reviewed_correction_reverses_vat_and_updates_earned_revenue_without_new_room_charge(): void
    {
        $service = new FolioNightAssessmentService();
        $draft = $service->prepare(1, 13, 9, 7);
        $approved = $service->approve(1, (int) $draft['id'], 8);
        $earned = $service->recognize(1, (int) $draft['id'], 8);

        $corrections = new FolioNightCorrectionService();
        $pending = $corrections->prepare(
            1, (int) $draft['id'], 9, 'senior', 11200,
            'verified-osca-reference', 'Apply qualifying guest room accommodation benefit'
        );
        try {
            $corrections->approve(1, (int) $pending['id'], 9);
            self::fail('Correction preparer cannot approve the correction.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reviewer', $exception->errors());
        }
        $corrected = $corrections->approve(1, (int) $pending['id'], 10);
        self::assertSame(3200, $corrected['deltaFolioCreditMinor']);
        self::assertSame(-1200, $corrected['deltaVatMinor']);
        self::assertSame(-2000, $corrected['deltaRevenueMinor']);
        self::assertSame(3200, (int) DB::table('folio_adjustments')->value('amount_minor'));
        self::assertSame('credit', DB::table('folio_adjustments')->value('kind'));
        self::assertSame(1200, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $corrected['journalId'])
            ->where('account_code', 'VAT_PAYABLE')->value('debit_minor'));
        self::assertSame(2000, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $corrected['journalId'])
            ->where('account_code', 'ROOM_REV')->value('debit_minor'));
        self::assertSame(3200, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $corrected['journalId'])
            ->where('account_code', 'AR')->value('credit_minor'));
        self::assertSame($earned, $service->recognize(1, (int) $draft['id'], 8));
        self::assertSame(1, DB::table('folio_entries')->count());
        self::assertSame(1, DB::table('folio_adjustments')->count());
    }

    public function test_correction_before_earned_night_revises_deferred_amount(): void
    {
        $service = new FolioNightAssessmentService();
        $draft = $service->prepare(1, 13, 9, 7);
        $service->approve(1, (int) $draft['id'], 8);
        $correction = (new FolioNightCorrectionService())->prepare(
            1, (int) $draft['id'], 9, 'pwd', 11200,
            'verified-pwd-reference', 'Correct verified eligible room portion'
        );
        $review = (new FolioNightCorrectionService())->approve(1, (int) $correction['id'], 10);
        self::assertSame(2000, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $review['journalId'])
            ->where('account_code', 'DEFERRED_ROOM_REV')->value('debit_minor'));
        $earned = $service->recognize(1, (int) $draft['id'], 10);
        self::assertSame(8000, (int) DB::table('financial_posting_lines')
            ->where('journal_id', $earned)->where('account_code', 'ROOM_REV')
            ->value('credit_minor'));
    }

    public function test_financial_close_rejects_missing_assessments_and_pending_corrections(): void
    {
        $close = new FinancialBusinessDayCloseService();
        try {
            $close->close(1, '2026-11-18', 7);
            self::fail('An unassessed room-night must prevent day close when accounting is active.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('folio_assessments', $exception->errors());
        }

        $service = new FolioNightAssessmentService();
        $draft = $service->prepare(1, 13, 9, 7);
        $service->approve(1, (int) $draft['id'], 8);
        try {
            $close->close(1, '2026-11-18', 7);
            self::fail('Earned revenue must exist before day close.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('revenue_recognition', $exception->errors());
        }

        $service->recognize(1, (int) $draft['id'], 8);
        (new FolioNightCorrectionService())->prepare(
            1, (int) $draft['id'], 9, 'senior', 11200,
            'verified-osca-reference', 'Review a verified eligible guest adjustment'
        );
        try {
            $close->close(1, '2026-11-18', 7);
            self::fail('Unreviewed correction must prevent final close.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('folio_corrections', $exception->errors());
        }
        self::assertSame('2026-11-18',
            DB::table('financial_business_clocks')->where('hotel_id', 1)->value('business_date'));
        self::assertSame(0, DB::table('financial_day_closes')->count());
    }

}

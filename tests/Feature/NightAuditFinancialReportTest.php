<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\NightAuditFinancialReportController;
use App\Models\Hotel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NightAuditFinancialReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
        });
        Schema::create('occupancy_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('room_id');
            $table->date('inspection_date');
            $table->string('status');
            $table->string('observed_occupancy')->nullable();
            $table->string('discrepancy')->nullable();
        });
        Schema::create('cashier_shifts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('status');
            $table->timestamp('submitted_at')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
        });
        Schema::create('night_audit_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->date('business_date');
            $table->string('status');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('snapshot_json');
            $table->string('snapshot_sha256', 64);
        });
        Schema::create('financial_day_closes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->date('business_date');
            $table->string('status');
            $table->timestamp('closed_at')->nullable();
            $table->text('reconciliation_snapshot');
        });
        Schema::create('financial_posting_journals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->date('business_date');
            $table->string('source_type');
        });
        Schema::create('financial_posting_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('journal_id');
            $table->string('account_code');
            $table->bigInteger('debit_minor');
            $table->bigInteger('credit_minor');
        });
    }

    protected function tearDown(): void
    {
        foreach (['financial_posting_lines', 'financial_posting_journals',
            'financial_day_closes', 'night_audit_reviews',
            'cashier_shifts', 'occupancy_verifications', 'rooms'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function report(int $hotelId = 1): array
    {
        $hotel = new Hotel();
        $hotel->id = $hotelId;
        $hotel->currency = 'PHP';
        $request = Request::create('/report', 'GET', ['date' => '2026-11-17']);
        return (new NightAuditFinancialReportController())($request, $hotel)->getData(true)['data'];
    }

    private function seedNightAuditFixture(): void
    {
        $audit = json_encode([
            'scheduledRoomNights' => 3, 'pricedRoomNights' => 3,
            'roomCount' => 10, 'postedRoomChargesMinor' => 150000,
        ], JSON_THROW_ON_ERROR);
        DB::table('night_audit_reviews')->insert([
            'hotel_id' => 1, 'business_date' => '2026-11-17',
            'status' => 'reviewed', 'snapshot_json' => $audit,
            'snapshot_sha256' => hash('sha256', $audit),
        ]);
        DB::table('financial_posting_journals')->insert([
            'id' => 100, 'hotel_id' => 1, 'business_date' => '2026-11-17',
            'source_type' => 'nightly_revenue',
        ]);
        DB::table('financial_posting_lines')->insert([
            ['journal_id' => 100, 'account_code' => 'AR', 'debit_minor' => 10000, 'credit_minor' => 0],
            ['journal_id' => 100, 'account_code' => 'ROOM_REV', 'debit_minor' => 0, 'credit_minor' => 10000],
        ]);
        DB::table('rooms')->insert(['id' => 1, 'hotel_id' => 1]);
        DB::table('occupancy_verifications')->insert([
            'hotel_id' => 1, 'room_id' => 1, 'inspection_date' => '2026-11-17',
            'status' => 'verified', 'observed_occupancy' => 'occupied', 'discrepancy' => 'match',
        ]);
        DB::table('financial_day_closes')->insert([
            'hotel_id' => 1, 'business_date' => '2026-11-17',
            'status' => 'completed',
            'reconciliation_snapshot' => json_encode([
                'journal_count' => 1, 'line_count' => 2, 'debits_minor' => 10000,
                'credits_minor' => 10000, 'currency' => 'PHP',
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_reconciles_closed_journals_without_misrepresenting_occupancy(): void
    {
        $this->seedNightAuditFixture();
        $data = $this->report();
        self::assertTrue($data['financialJournalReconciled']);
        self::assertSame([], $data['blockers']);
        self::assertSame(10000, $data['financialClose']['debitsMinor']);
        self::assertSame(3, $data['nightAudit']['scheduledRoomNights']);
        self::assertNull($data['kpis']['verifiedOccupiedRoomNights']);
        self::assertNull($data['kpis']['adrMinor']);
        self::assertNull($data['kpis']['revparMinor']);
        self::assertTrue($data['kpis']['inspectionCoverageComplete']);
        self::assertEquals(100.0, $data['kpis']['inspectionOccupancyPercent']);
        self::assertSame(10000, $data['kpis']['recognizedRoomRevenueMinor']);
    }

    public function test_mutated_ledger_after_close_is_explicitly_flagged(): void
    {
        $this->seedNightAuditFixture();
        DB::table('financial_posting_lines')->where('journal_id', 100)
            ->where('credit_minor', 10000)->update(['credit_minor' => 9000]);
        $data = $this->report();
        self::assertFalse($data['financialJournalReconciled']);
        self::assertSame(1, $data['financialClose']['unbalancedJournalCount']);
        self::assertFalse($data['financialClose']['snapshotMatchesLedger']);
    }

    public function test_a_second_hotel_never_reads_the_first_hotels_reconciliation(): void
    {
        $this->seedNightAuditFixture();
        $data = $this->report(2);
        self::assertSame('2', $data['hotelId']);
        self::assertSame('missing', $data['nightAudit']['status']);
        self::assertSame(0, $data['financialClose']['journalCount']);
        self::assertFalse($data['financialJournalReconciled']);
    }

    public function test_legacy_booking_journal_does_not_count_as_earned_revenue(): void
    {
        $this->seedNightAuditFixture();
        DB::table('financial_posting_journals')->where('id', 100)
            ->update(['source_type' => 'folio']);
        $data = $this->report();
        self::assertTrue($data['financialJournalReconciled']);
        self::assertSame(0, $data['kpis']['recognizedRoomRevenueMinor']);
    }

    public function test_cashier_discrepancies_are_visible_and_block_clean_reconciliation(): void
    {
        $this->seedNightAuditFixture();
        DB::table('cashier_shifts')->insert([
            'hotel_id' => 1, 'status' => 'flagged',
            'submitted_at' => '2026-11-17 08:00:00',
            'expected_cash_minor' => 12000,
            'counted_cash_minor' => 11000, 'variance_minor' => -1000,
        ]);
        $data = $this->report();
        self::assertSame(1, $data['cashier']['flaggedCount']);
        self::assertSame(-1000, $data['cashier']['varianceMinor']);
        self::assertTrue($data['financialJournalReconciled']);
        self::assertFalse($data['allReportChecksClear']);
    }

    public function test_vat_posting_and_room_revenue_reversal_are_net_account_movements(): void
    {
        $this->seedNightAuditFixture();
        // Add a balanced VAT journal while preserving matching close totals.
        DB::table('financial_posting_journals')->insert([
            'id' => 101, 'hotel_id' => 1, 'business_date' => '2026-11-17',
            'source_type' => 'folio_tax_correction',
        ]);
        DB::table('financial_posting_lines')->insert([
            ['journal_id'=>101,'account_code'=>'ROOM_REV','debit_minor'=>1000,'credit_minor'=>0],
            ['journal_id'=>101,'account_code'=>'VAT_PAYABLE','debit_minor'=>0,'credit_minor'=>1000],
        ]);
        DB::table('financial_day_closes')->where('hotel_id', 1)->update([
            'reconciliation_snapshot' => json_encode([
                'journal_count'=>2,'line_count'=>4,'debits_minor'=>11000,
                'credits_minor'=>11000,'currency'=>'PHP',
            ], JSON_THROW_ON_ERROR),
        ]);
        $data = $this->report();
        self::assertSame(9000, $data['kpis']['recognizedRoomRevenueMinor']);
        self::assertSame(1000, $data['kpis']['vatLiabilityMovementMinor']);
        self::assertTrue($data['financialJournalReconciled']);
    }

    public function test_tampered_audit_snapshot_is_not_trusted(): void
    {
        $this->seedNightAuditFixture();
        DB::table('night_audit_reviews')->where('hotel_id', 1)
            ->update(['snapshot_json' => '{"roomCount":999}']);
        $data = $this->report();
        self::assertFalse($data['nightAudit']['snapshotIntegrityValid']);
        self::assertNull($data['nightAudit']['roomCount']);
        self::assertFalse($data['financialJournalReconciled']);
    }
}

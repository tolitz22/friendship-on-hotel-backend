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
        });
        Schema::create('financial_posting_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('journal_id');
            $table->bigInteger('debit_minor');
            $table->bigInteger('credit_minor');
        });
    }

    protected function tearDown(): void
    {
        foreach (['financial_posting_lines', 'financial_posting_journals',
            'financial_day_closes', 'night_audit_reviews'] as $table) {
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

    private function seed(): void
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
        ]);
        DB::table('financial_posting_lines')->insert([
            ['journal_id' => 100, 'debit_minor' => 10000, 'credit_minor' => 0],
            ['journal_id' => 100, 'debit_minor' => 0, 'credit_minor' => 10000],
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
        $this->seed();
        $data = $this->report();
        self::assertTrue($data['financialJournalReconciled']);
        self::assertSame([], $data['blockers']);
        self::assertSame(10000, $data['financialClose']['debitsMinor']);
        self::assertSame(3, $data['nightAudit']['scheduledRoomNights']);
        self::assertNull($data['kpis']['verifiedOccupiedRoomNights']);
        self::assertNull($data['kpis']['adrMinor']);
        self::assertNull($data['kpis']['revparMinor']);
    }

    public function test_mutated_ledger_after_close_is_explicitly_flagged(): void
    {
        $this->seed();
        DB::table('financial_posting_lines')->where('journal_id', 100)
            ->where('credit_minor', 10000)->update(['credit_minor' => 9000]);
        $data = $this->report();
        self::assertFalse($data['financialJournalReconciled']);
        self::assertSame(1, $data['financialClose']['unbalancedJournalCount']);
        self::assertFalse($data['financialClose']['snapshotMatchesLedger']);
    }

    public function test_a_second_hotel_never_reads_the_first_hotels_reconciliation(): void
    {
        $this->seed();
        $data = $this->report(2);
        self::assertSame('2', $data['hotelId']);
        self::assertSame('missing', $data['nightAudit']['status']);
        self::assertSame(0, $data['financialClose']['journalCount']);
        self::assertFalse($data['financialJournalReconciled']);
    }

    public function test_tampered_audit_snapshot_is_not_trusted(): void
    {
        $this->seed();
        DB::table('night_audit_reviews')->where('hotel_id', 1)
            ->update(['snapshot_json' => '{"roomCount":999}']);
        $data = $this->report();
        self::assertFalse($data['nightAudit']['snapshotIntegrityValid']);
        self::assertNull($data['nightAudit']['roomCount']);
        self::assertFalse($data['financialJournalReconciled']);
    }
}

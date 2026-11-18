<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reservations\FolioAccountingService;
use App\Domain\Reservations\FolioSettlementService;
use App\Domain\Reservations\FolioRefundDisbursementService;
use App\Models\Hotel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class FolioSettlementIntegrationTest extends TestCase
{
    private ?string $previousConnection = null;
    private bool $isolatedSqlite = false;

    private const TABLES = [
        'cashier_shift_receipts', 'cashier_shifts',
        'folio_refund_disbursement_evidence',
        'folio_settlement_signoffs', 'folio_settlement_allocations',
        'folio_settlement_verifications', 'financial_folio_night_assessments',
        'folio_refund_requests', 'folio_payment_records', 'folio_adjustments',
        'folio_entries', 'reservation_folios', 'reservation_records', 'hotels',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // This legacy service-contract fixture deliberately uses a miniature
        // schema. Never write it over the application's migrated tables.
        // SQLite uses a private connection and separate in-memory database.
        // MySQL/PostgreSQL must run in the disposable per-file CI database
        // supplied by scripts/run-isolated-db-tests.sh.
        $this->previousConnection = DB::getDefaultConnection();
        if (DB::connection()->getDriverName() === 'sqlite') {
            $name = 'finance_settlement_contract';
            $settings = config('database.connections.'.$this->previousConnection);
            config()->set('database.connections.'.$name, array_merge($settings, [
                'database' => ':memory:',
            ]));
            DB::purge($name);
            DB::setDefaultConnection($name);
            $this->isolatedSqlite = true;
        } elseif (Schema::hasTable('hotels')) {
            throw new \RuntimeException(
                'FolioSettlementIntegrationTest requires a disposable, empty MySQL/PostgreSQL test database; do not run against migrated application tables.'
            );
        }

        config()->set('financial_accounting.enable_verified_settlement', true);
        config()->set('financial_accounting.enable_live_folio_tax', false);
        Schema::create('hotels', function (Blueprint $t): void {
            $t->id();$t->string('timezone')->nullable();
        });
        Schema::create('reservation_records', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');
            $t->string('reference');$t->string('lifecycle_state');
            $t->date('check_in');$t->date('check_out');
        });
        Schema::create('reservation_folios', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('reservation_record_id');
            $t->string('currency');$t->timestamps();
        });
        Schema::create('folio_entries', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->string('entry_type');$t->string('description');
            $t->date('stay_date');$t->bigInteger('amount_minor');
        });
        Schema::create('folio_adjustments', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->string('request_key');$t->string('payload_sha256');
            $t->string('kind');$t->bigInteger('amount_minor');$t->string('reason');
            $t->unsignedBigInteger('actor_id');$t->timestamps();
        });
        Schema::create('folio_payment_records', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->string('request_key');$t->string('payload_sha256');
            $t->string('method');$t->string('reference');$t->bigInteger('amount_minor');
            $t->string('status');$t->unsignedBigInteger('recorded_by');
            $t->unsignedBigInteger('reviewed_by')->nullable();$t->text('review_note')->nullable();
            $t->timestamps();
        });
        Schema::create('folio_refund_requests', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->unsignedBigInteger('payment_record_id');
            $t->bigInteger('amount_minor');$t->string('reason');$t->string('status');
            $t->unsignedBigInteger('requested_by')->default(7);$t->unsignedBigInteger('reviewed_by')->nullable();
        });
        Schema::create('folio_refund_disbursement_evidence', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->unsignedBigInteger('refund_request_id');$t->unsignedBigInteger('payment_record_id');
            $t->bigInteger('amount_minor');$t->string('external_reference');
            $t->string('status');$t->unsignedBigInteger('recorded_by');
            $t->unsignedBigInteger('reviewed_by')->nullable();$t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();$t->timestamps();
            $t->unique(['hotel_id','refund_request_id'], 'refund_disbursement_per_request');
        });
        Schema::create('financial_folio_night_assessments', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->string('status');
            $t->unsignedBigInteger('folio_entry_id')->nullable();
            $t->date('stay_date')->nullable();
            $t->bigInteger('gross_minor')->default(0);
            $t->bigInteger('vat_minor')->default(0);
            $t->bigInteger('room_revenue_minor')->default(0);
            $t->bigInteger('folio_credit_minor')->default(0);
            $t->string('benefit_kind')->nullable();
        });
        Schema::create('cashier_shifts', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->string('status');
        });
        Schema::create('cashier_shift_receipts', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('shift_id');
            $t->unsignedBigInteger('payment_id');$t->bigInteger('amount_minor');
        });
        Schema::create('folio_settlement_verifications', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->unsignedBigInteger('payment_record_id');$t->string('evidence_kind');
            $t->string('evidence_reference');$t->string('status');
            $t->unsignedBigInteger('prepared_by');$t->unsignedBigInteger('reviewed_by')->nullable();
            $t->text('review_note')->nullable();$t->timestamp('reviewed_at')->nullable();
            $t->timestamps();$t->unique(['hotel_id', 'payment_record_id']);
        });
        Schema::create('folio_settlement_allocations', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->unsignedBigInteger('payment_record_id');$t->unsignedBigInteger('folio_entry_id')->nullable();
            $t->string('payer_kind');$t->string('payer_reference');$t->bigInteger('amount_minor');
            $t->string('idempotency_key');$t->unsignedBigInteger('allocated_by');$t->timestamp('created_at');
            $t->unique(['hotel_id', 'idempotency_key']);
        });
        Schema::create('folio_settlement_signoffs', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('hotel_id');$t->unsignedBigInteger('folio_id');
            $t->unsignedBigInteger('reservation_record_id');$t->unsignedBigInteger('approved_by');
            $t->bigInteger('net_charges_minor');$t->bigInteger('verified_allocated_minor');
            $t->char('snapshot_sha256',64);$t->text('statement_snapshot');
            $t->timestamp('signed_at');$t->unique(['hotel_id','folio_id']);
        });
        DB::table('hotels')->insert(['id' => 1, 'timezone' => 'Asia/Manila']);
        DB::table('reservation_records')->insert([
            'id' => 11, 'hotel_id' => 1, 'reference' => 'BK-PHASE61',
            'lifecycle_state' => 'checked_in',
            'check_in' => now('Asia/Manila')->subDays(2)->toDateString(),
            'check_out' => now('Asia/Manila')->toDateString(),
        ]);
        DB::table('reservation_folios')->insert([
            'id' => 12, 'hotel_id' => 1, 'reservation_record_id' => 11, 'currency' => 'PHP',
        ]);
        DB::table('folio_entries')->insert([
            'id' => 13, 'hotel_id' => 1, 'folio_id' => 12,
            'entry_type' => 'room_night', 'description' => 'Test nightly accommodation',
            'stay_date' => now('Asia/Manila')->subDay()->toDateString(),
            'amount_minor' => 11200,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        if ($this->previousConnection !== null) {
            $previous = $this->previousConnection;
            if ($this->isolatedSqlite) {
                DB::disconnect('finance_settlement_contract');
                DB::setDefaultConnection($previous);
                DB::purge('finance_settlement_contract');
            }
        }
        parent::tearDown();
    }

    private function hotel(): Hotel
    {
        $hotel = new Hotel();
        $hotel->id = 1;
        $hotel->timezone = 'Asia/Manila';
        return $hotel;
    }

    private function paymentEvidence(int $amount = 11200): int
    {
        return DB::table('folio_payment_records')->insertGetId([
            'hotel_id' => 1, 'folio_id' => 12,
            'request_key' => 'deposit-payment-'.$amount, 'payload_sha256' => str_repeat('a', 64),
            'method' => 'bank_transfer', 'reference' => 'BANK-TEST-'.$amount,
            'amount_minor' => $amount, 'status' => 'staff_confirmed',
            'recorded_by' => 4,
        ]);
    }

    public function test_bank_evidence_requires_independent_review_and_allocation_is_idempotent(): void
    {
        $service = new FolioSettlementService();
        $hotel = $this->hotel();
        $paymentId = $this->paymentEvidence();
        $pending = $service->prepareVerification($hotel, 11, $paymentId, 7,
            'bank_statement', 'statement-match-008');
        self::assertSame('pending', $pending['status']);
        try {
            $service->reviewVerification($hotel, 11, (int) $pending['id'], 7,
                'verify', 'Statement independently checked.');
            self::fail('Evidence preparer cannot verify their own work.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reviewer', $exception->errors());
        }
        $verified = $service->reviewVerification($hotel, 11, (int) $pending['id'], 8,
            'verify', 'Bank evidence matched manually for testing.');
        self::assertSame('verified', $verified['status']);

        $deposit = $service->statement($hotel, 11);
        self::assertSame(11200, $deposit['unallocatedVerifiedDepositsMinor']);
        self::assertFalse($deposit['canSignOff']);

        $allocated = $service->allocate($hotel, 11, $paymentId, 8,
            'allocation-one', 'guest', 'guest-11', 11200, 13);
        self::assertFalse($allocated['replayed']);
        $replayed = $service->allocate($hotel, 11, $paymentId, 8,
            'allocation-one', 'guest', 'guest-11', 11200, 13);
        self::assertSame($allocated['id'], $replayed['id']);
        self::assertTrue($replayed['replayed']);
        $statement = $service->statement($hotel, 11);
        self::assertTrue($statement['canSignOff']);
        self::assertSame(0, $statement['remainingMinor']);
        self::assertSame(0, $statement['unallocatedVerifiedDepositsMinor']);

        $signoff = $service->signoff($hotel, 11, 8);
        self::assertFalse($signoff['replayed']);
        self::assertSame($signoff['id'], $service->signoff($hotel, 11, 8)['id']);
        self::assertSame(1, DB::table('folio_settlement_signoffs')->count());
        self::assertSame(1, DB::table('folio_entries')->count());
        try {
            app(FolioAccountingService::class)->adjust($hotel, 11, 8, [
                'requestKey' => 'post-sign-1', 'kind' => 'debit',
                'amountMinor' => 100, 'reason' => 'Post-sign charge',
            ]);
            self::fail('Signed folio must not accept charges.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_unverified_payment_and_overallocation_do_not_settle(): void
    {
        $service = new FolioSettlementService();
        $hotel = $this->hotel();
        $paymentId = $this->paymentEvidence();
        try {
            $service->allocate($hotel, 11, $paymentId, 8,
                'allocation-no-verify', 'company', 'company-acme', 11200, null);
            self::fail('Payment must be verified first.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('payment', $exception->errors());
        }
        $pending = $service->prepareVerification($hotel, 11, $paymentId, 7,
            'bank_statement', 'bank-manual-match');
        $service->reviewVerification($hotel, 11, (int) $pending['id'], 8,
            'verify', 'Checked posted bank statement evidence.');
        $service->allocate($hotel, 11, $paymentId, 8,
            'allocation-first-1', 'company', 'company-acme', 6000, null);
        try {
            $service->allocate($hotel, 11, $paymentId, 8,
                'allocation-second', 'company', 'company-acme', 6000, null);
            self::fail('Never allocate more than a payment amount.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amountMinor', $exception->errors());
        }
        self::assertFalse($service->statement($hotel, 11)['canSignOff']);
        self::assertSame(6000, (int) DB::table('folio_settlement_allocations')->sum('amount_minor'));
    }

    public function test_pending_refunds_block_settlement_signoff(): void
    {
        $service = new FolioSettlementService();
        $hotel = $this->hotel();
        $id = $this->paymentEvidence();
        $v = $service->prepareVerification($hotel, 11, $id, 7, 'bank_statement', 'statement-match-021');
        $service->reviewVerification($hotel, 11, (int) $v['id'], 8,
            'verify', 'Bank evidence independently reviewed.');
        $service->allocate($hotel, 11, $id, 8, 'allocate-refund-test',
            'guest', 'guest-11', 11200, null);
        DB::table('folio_refund_requests')->insert([
            'hotel_id' => 1, 'folio_id' => 12, 'payment_record_id' => $id,
            'amount_minor' => 500, 'reason' => 'Refund request pending', 'status' => 'approved',
        ]);
        self::assertFalse($service->statement($hotel, 11)['canSignOff']);
        try {
            $service->signoff($hotel, 11, 8);
            self::fail('Outstanding refunds block immutable settlement.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('settlement', $exception->errors());
        }
        self::assertSame(0, DB::table('folio_settlement_signoffs')->count());
    }
    public function test_documented_bank_refund_frees_only_unallocated_deposit(): void
    {
        $service = new FolioSettlementService();
        $hotel = $this->hotel();
        $paymentId = $this->paymentEvidence(15000);
        $verification = $service->prepareVerification($hotel, 11, $paymentId, 7,
            'bank_statement', 'statement-match-refund-01');
        $service->reviewVerification($hotel, 11, (int) $verification['id'], 8,
            'verify', 'Bank statement line matched independently.');
        $service->allocate($hotel, 11, $paymentId, 8, 'allocate-refund-seed',
            'guest', 'guest-11', 11200, 13);

        $refundId = DB::table('folio_refund_requests')->insertGetId([
            'hotel_id' => 1, 'folio_id' => 12, 'payment_record_id' => $paymentId,
            'amount_minor' => 3800, 'reason' => 'Refund unused deposit',
            'status' => 'approved', 'requested_by' => 7,
        ]);
        $refunder = new FolioRefundDisbursementService();
        $pending = $refunder->record($hotel, 11, $refundId, 8, 'BANK-REFUND-PROOF-10');
        self::assertSame('pending', $pending['status']);
        self::assertFalse($service->statement($hotel, 11)['canSignOff']);

        try {
            $refunder->review($hotel, 11, (int) $pending['id'], 8,
                'verify', 'External refund reference independently matched.');
            self::fail('Refund proof preparer cannot verify their own evidence.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reviewer', $exception->errors());
        }
        $verified = $refunder->review($hotel, 11, (int) $pending['id'], 9,
            'verify', 'Reviewed bank refund statement independently.');
        self::assertSame('verified', $verified['status']);
        $statement = $service->statement($hotel, 11);
        self::assertSame(3800, $statement['verifiedRefundsMinor']);
        self::assertSame(0, $statement['unallocatedVerifiedDepositsMinor']);
        self::assertTrue($statement['canSignOff']);
        $service->signoff($hotel, 11, 9);
        self::assertSame(1, DB::table('folio_refund_disbursement_evidence')->count());
    }

    public function test_refund_cannot_reuse_allocated_amount_as_disbursement(): void
    {
        $service = new FolioSettlementService();
        $hotel = $this->hotel();
        $paymentId = $this->paymentEvidence(15000);
        $verification = $service->prepareVerification($hotel, 11, $paymentId, 7,
            'bank_statement', 'statement-match-refund-02');
        $service->reviewVerification($hotel, 11, (int) $verification['id'], 8,
            'verify', 'Supporting bank statement independently matched.');
        $service->allocate($hotel, 11, $paymentId, 8, 'allocate-before-refund',
            'guest', 'guest-11', 11200, null);

        $refundId = DB::table('folio_refund_requests')->insertGetId([
            'hotel_id' => 1, 'folio_id' => 12, 'payment_record_id' => $paymentId,
            'amount_minor' => 4000, 'reason' => 'Excessive approved refund',
            'status' => 'approved', 'requested_by' => 7,
        ]);
        try {
            (new FolioRefundDisbursementService())->record(
                $hotel, 11, $refundId, 8, 'BANK-REFUND-PROOF-11'
            );
            self::fail('Refund disbursement cannot consume allocated room charges.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('refund', $exception->errors());
        }
        self::assertSame(0, DB::table('folio_refund_disbursement_evidence')->count());
    }

    public function test_signed_statement_detects_out_of_band_tampering(): void
    {
        $service = new FolioSettlementService();
        $hotel = $this->hotel();
        $paymentId = $this->paymentEvidence();
        $v = $service->prepareVerification($hotel, 11, $paymentId, 7,
            'bank_statement', 'statement-integrity-evidence');
        $service->reviewVerification($hotel, 11, (int) $v['id'], 8,
            'verify', 'Independently reconciled bank evidence.');
        $service->allocate($hotel, 11, $paymentId, 8, 'signed-integrity-key',
            'guest', 'guest-11', 11200, 13);
        $service->signoff($hotel, 11, 8);
        self::assertTrue($service->statement($hotel, 11)['signedSnapshotIntegrity']);

        // Simulate an illicit direct SQL edit bypassing all application guards.
        DB::table('folio_entries')->where('id', 13)->update(['amount_minor' => 11300]);
        $tampered = $service->statement($hotel, 11);
        self::assertTrue($tampered['signed']);
        self::assertFalse($tampered['signedSnapshotIntegrity']);
        self::assertFalse($tampered['canSignOff']);
        self::assertNotEmpty($tampered['blockers']);
    }

    public function test_settlement_mutations_are_disabled_by_default(): void
    {
        $hotel = $this->hotel();
        $paymentId = $this->paymentEvidence();
        config()->set('financial_accounting.enable_verified_settlement', false);
        $service = new FolioSettlementService();
        self::assertFalse($service->statement($hotel, 11)['settlementWorkflowEnabled']);
        try {
            $service->prepareVerification($hotel, 11, $paymentId, 7,
                'bank_statement', 'documentary-match-disabled');
            self::fail('Settlement writes must remain disabled until explicitly activated.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('activation', $exception->errors());
        }
        self::assertSame(0, DB::table('folio_settlement_verifications')->count());
    }

    public function test_duplicate_bank_evidence_and_net_folio_overallocation_are_rejected(): void
    {
        $service = new FolioSettlementService();
        $hotel = $this->hotel();
        $first = $this->paymentEvidence(15000);
        $second = $this->paymentEvidence(10000);
        $draft = $service->prepareVerification($hotel, 11, $first, 7,
            'bank_statement', 'BANK-DUPLICATE-TEST');
        try {
            $service->prepareVerification($hotel, 11, $second, 7,
                'bank_statement', 'BANK-DUPLICATE-TEST');
            self::fail('The same bank proof cannot verify two different payments.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('evidence', $exception->errors());
        }
        $service->reviewVerification($hotel, 11, (int) $draft['id'], 8,
            'verify', 'Bank transaction independently matched.');
        try {
            $service->allocate($hotel, 11, $first, 8,
                'allocation-exceeds-net', 'guest', 'guest-11', 13000, null);
            self::fail('No allocation may exceed net folio charges.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amountMinor', $exception->errors());
        }
        self::assertSame(0, DB::table('folio_settlement_allocations')->count());
    }

    public function test_cash_confirmation_requires_an_independently_reconciled_shift(): void
    {
        $service = new FolioSettlementService();
        $hotel = $this->hotel();
        $payment = $this->paymentEvidence();
        DB::table('folio_payment_records')->where('id', $payment)->update([
            'method' => 'cash', 'reference' => 'CASH-REGISTER-11200',
        ]);
        DB::table('cashier_shifts')->insert([
            'id' => 10, 'hotel_id' => 1, 'status' => 'submitted',
        ]);
        DB::table('cashier_shift_receipts')->insert([
            'hotel_id' => 1, 'shift_id' => 10,
            'payment_id' => $payment, 'amount_minor' => 11200,
        ]);
        $pending = $service->prepareVerification($hotel, 11, $payment, 7,
            'cash_shift', 'shift-10');
        try {
            $service->reviewVerification($hotel, 11, (int) $pending['id'], 8,
                'verify', 'Submitted shift must not pass review.');
            self::fail('Cash requires a reconciled shift.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('payment', $exception->errors());
        }
        DB::table('cashier_shifts')->where('id', 10)->update(['status' => 'reconciled']);
        $reviewed = $service->reviewVerification($hotel, 11, (int) $pending['id'], 8,
            'verify', 'Cash drawer shift has been independently reconciled.');
        self::assertSame('verified', $reviewed['status']);
    }

}

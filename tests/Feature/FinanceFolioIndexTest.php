<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reservations\FolioAccountingService;
use App\Http\Controllers\Api\FinanceFolioIndexController;
use App\Models\Hotel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Isolated database contract: no production hotel, payment provider, or real funds. */
final class FinanceFolioIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('guests', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('hotel_id');
            $t->string('first_name'); $t->string('last_name');
        });
        Schema::create('reservation_records', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('hotel_id'); $t->unsignedBigInteger('guest_id')->nullable();
            $t->string('reference');
            $t->date('check_in'); $t->date('check_out'); $t->string('lifecycle_state');
        });
        Schema::create('reservation_folios', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('hotel_id'); $t->unsignedBigInteger('reservation_record_id');
            $t->string('currency');
        });
        Schema::create('folio_entries', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('hotel_id'); $t->unsignedBigInteger('folio_id');
            $t->bigInteger('amount_minor');
        });
        Schema::create('folio_adjustments', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('hotel_id'); $t->unsignedBigInteger('folio_id');
            $t->string('kind'); $t->bigInteger('amount_minor');
        });
        Schema::create('folio_payment_records', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('hotel_id'); $t->unsignedBigInteger('folio_id');
            $t->string('status'); $t->bigInteger('amount_minor');
        });
        Schema::create('folio_settlement_signoffs', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('hotel_id'); $t->unsignedBigInteger('folio_id');
        });
    }

    protected function tearDown(): void
    {
        foreach (['folio_settlement_signoffs', 'folio_payment_records', 'folio_adjustments',
            'folio_entries', 'reservation_folios', 'reservation_records', 'guests'] as $name) {
            Schema::dropIfExists($name);
        }
        parent::tearDown();
    }

    private function seedFolio(int $hotelId, int $bookingId, int $folioId, string $reference): void
    {
        DB::table('reservation_records')->insert([
            'id' => $bookingId, 'hotel_id' => $hotelId, 'reference' => $reference,
            'check_in' => '2026-11-19', 'check_out' => '2026-11-20',
            'lifecycle_state' => 'confirmed',
        ]);
        DB::table('reservation_folios')->insert([
            'id' => $folioId, 'hotel_id' => $hotelId,
            'reservation_record_id' => $bookingId, 'currency' => 'PHP',
        ]);
    }

    private function index(int $hotelId, array $params = []): array
    {
        $hotel = new Hotel();
        $hotel->id = $hotelId;
        $request = Request::create('/api/v1/finance/folios', 'GET', $params);
        $response = (new FinanceFolioIndexController())($request, $hotel, new FolioAccountingService());
        return $response->getData(true);
    }

    public function test_lists_only_selected_hotel_and_computes_posted_provisional_amounts(): void
    {
        $this->seedFolio(1, 11, 101, 'HOTEL-A-11');
        $this->seedFolio(2, 22, 202, 'HOTEL-B-22');
        DB::table('folio_entries')->insert([
            ['hotel_id' => 1, 'folio_id' => 101, 'amount_minor' => 15000],
            ['hotel_id' => 2, 'folio_id' => 202, 'amount_minor' => 50000],
        ]);
        DB::table('folio_adjustments')->insert([
            'hotel_id' => 1, 'folio_id' => 101, 'kind' => 'credit', 'amount_minor' => 1000,
        ]);
        DB::table('folio_payment_records')->insert([
            'hotel_id' => 1, 'folio_id' => 101, 'status' => 'pending_review', 'amount_minor' => 4000,
        ]);
        $result = $this->index(1);
        self::assertSame(1, $result['meta']['total']);
        self::assertSame('11', $result['data'][0]['bookingId']);
        self::assertSame(14000, $result['data'][0]['netRecordedChargesMinor']);
        self::assertSame(4000, $result['data'][0]['pendingReviewPaymentsMinor']);
        self::assertSame(0, $result['data'][0]['staffConfirmedPaymentsMinor']);
        self::assertTrue($result['meta']['paymentEvidenceNotIndependentlyVerified']);
    }

    public function test_guest_name_search_is_restricted_to_the_selected_hotel(): void
    {
        DB::table('guests')->insert([
            ['id' => 1, 'hotel_id' => 1, 'first_name' => 'Maria', 'last_name' => 'Santos'],
            ['id' => 2, 'hotel_id' => 2, 'first_name' => 'Maria', 'last_name' => 'Santos'],
        ]);
        $this->seedFolio(1, 11, 101, 'ONE-A');
        $this->seedFolio(2, 12, 102, 'TWO-A');
        DB::table('reservation_records')->where('id', 11)->update(['guest_id' => 1]);
        DB::table('reservation_records')->where('id', 12)->update(['guest_id' => 2]);

        $result = $this->index(1, ['search' => 'Maria Santos']);
        self::assertSame(1, $result['meta']['total']);
        self::assertSame('11', $result['data'][0]['bookingId']);
        self::assertSame('Maria Santos', $result['data'][0]['guestName']);
    }

    public function test_search_and_pagination_do_not_leak_other_hotel_accounts(): void
    {
        $this->seedFolio(1, 10, 100, 'ONE-001');
        $this->seedFolio(1, 11, 101, 'ONE-002');
        $this->seedFolio(2, 12, 102, 'ONE-003');
        $page = $this->index(1, ['page' => 2, 'per_page' => 1]);
        self::assertSame(2, $page['meta']['total']);
        self::assertSame(2, $page['meta']['totalPages']);
        self::assertSame('10', $page['data'][0]['bookingId']);
        self::assertSame([], $this->index(1, ['search' => 'ONE-003'])['data']);
        self::assertSame('11', $this->index(1, ['search' => '11'])['data'][0]['bookingId']);
    }

    public function test_missing_optional_signoff_history_does_not_break_billing_directory(): void
    {
        $this->seedFolio(1, 10, 100, 'ONE-010');
        Schema::drop('folio_settlement_signoffs');
        $result = $this->index(1);
        self::assertSame(1, $result['meta']['total']);
        self::assertFalse($result['meta']['signoffRecordsAvailable']);
        self::assertNull($result['data'][0]['signed']);
        self::assertTrue($result['data'][0]['provisionalOnly']);
    }

    public function test_signed_folios_are_marked_without_claiming_bank_verification(): void
    {
        $this->seedFolio(1, 10, 100, 'ONE-010');
        DB::table('folio_settlement_signoffs')->insert(['hotel_id' => 1, 'folio_id' => 100]);
        $result = $this->index(1);
        self::assertTrue($result['data'][0]['signed']);
        self::assertTrue($result['data'][0]['provisionalOnly']);
    }
}

<?php

declare(strict_types=1);

use App\Domain\Reservations\NoShowFinancialReviewService;
use App\Domain\Reservations\ReservationBookingService;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$driver = DB::getDriverName();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'friendship_on_hotel_test'
    || ! in_array($driver, ['pgsql', 'mysql'], true)) {
    fwrite(STDERR, "Use a disposable, migrated MySQL or PostgreSQL test database.\n");
    exit(2);
}

function configureRace(int $hotelId): void
{
    config()->set([
        'booking.no_show_adjustments_enabled' => true,
        'booking.no_show_adjustments_hotel_ids' => (string) $hotelId,
        'financial_accounting.enable_verified_settlement' => true,
        'financial_accounting.enable_guest_deposit_posting' => true,
        'financial_accounting.enable_no_show_allocation_posting' => true,
        'financial_accounting.enable_cash_guest_deposit_posting' => true,
    ]);
}

if (($argv[1] ?? '') === 'worker') {
    [, , $barrier, $index, $hotelId, $bookingId, $paymentId, $actorId, $mode] = $argv;
    configureRace((int) $hotelId);
    $hotel = Hotel::findOrFail($hotelId);
    touch($barrier.'.ready.'.$index);
    $deadline = microtime(true) + 30;
    while (!file_exists($barrier) && microtime(true) < $deadline) usleep(10000);
    if (!file_exists($barrier)) exit(3);
    try {
        if (in_array($mode, ['deposit-bank', 'deposit-cash'], true)) {
            $journal = app(\App\Services\Finance\GuestDepositJournalService::class)
                ->recognize($hotel, (int) $bookingId, (int) $paymentId, (int) $actorId);
            file_put_contents($barrier.'.journal.'.$index, (string) $journal);
            exit(0);
        }
        $result = app(\App\Domain\Reservations\FolioSettlementService::class)->allocate(
            $hotel, (int) $bookingId, (int) $paymentId, (int) $actorId,
            $mode === 'duplicate' ? 'race-shared-allocation' : 'race-allocation-'.$index,
            'guest', 'Synthetic guest', 120000, null
        );
        exit($result['replayed'] ? 8 : 0);
    } catch (\Illuminate\Validation\ValidationException $exception) {
        if ($mode === 'competing' && isset($exception->errors()['amountMinor'])) exit(9);
        throw $exception;
    }
}

$mode = $argv[1] ?? 'competing';
if (!in_array($mode, ['competing', 'duplicate', 'deposit-bank', 'deposit-cash'], true)) throw new RuntimeException('Invalid race mode.');
$isDeposit = in_array($mode, ['deposit-bank', 'deposit-cash'], true);
$hotel = Hotel::create(['name' => 'No-show allocation race fixture', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
$actor = User::create([
    'name' => 'No-show race staff', 'email' => 'ns-race-'.bin2hex(random_bytes(5)).'@example.test',
    'password' => 'SyntheticRacePassword123!',
]);
$type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '101']);
$guest = $hotel->guests()->create(['first_name' => 'Synthetic', 'last_name' => 'No-show']);
$rate = DB::table('room_rate_plans')->insertGetId([
    'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'name' => 'Race test rate',
    'nightly_minor' => 300000, 'currency' => 'PHP', 'created_by' => $actor->id,
]);
$today = now('Asia/Manila')->toDateString();
$record = app(ReservationBookingService::class)->create($hotel, $actor->id, [
    'idempotencyKey' => 'no-show-race-booking', 'guestId' => $guest->id, 'roomTypeId' => $type->id,
    'checkIn' => now('Asia/Manila')->subDays(3)->toDateString(),
    'checkOut' => now('Asia/Manila')->subDays(1)->toDateString(), 'ratePlanId' => $rate,
]);
$bookingId = (int) $record['id'];
DB::table('booking_pricing_snapshots')->insert([
    'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId, 'quote_token' => str_repeat('a', 64),
    'snapshot' => json_encode(['policyTerms' => ['version' => 1, 'noShowKind' => 'fixed', 'noShowValue' => 200000]], JSON_THROW_ON_ERROR),
    'created_by' => $actor->id, 'created_at' => now(),
]);
DB::table('financial_business_clocks')->insert(['hotel_id' => $hotel->id, 'business_date' => $today, 'status' => 'open']);
configureRace((int) $hotel->id);
app(ReservationBookingService::class)->cancel($hotel, $actor->id, $bookingId, true);
$fingerprint = app(NoShowFinancialReviewService::class)->preview($hotel, $bookingId)['reviewFingerprint'];

$reviewer = User::create([
    'name' => 'Independent race reviewer', 'email' => 'ns-review-'.bin2hex(random_bytes(5)).'@example.test',
    'password' => 'SyntheticRacePassword123!', 'is_active' => true,
]);
foreach ([$actor, $reviewer] as $staff) {
    \App\Models\HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id, 'role' => 'admin', 'is_active' => true]);
}
DB::table('financial_accounting_periods')->insert([
    'hotel_id' => $hotel->id, 'starts_on' => now('Asia/Manila')->startOfMonth()->toDateString(),
    'ends_on' => now('Asia/Manila')->endOfMonth()->toDateString(), 'status' => 'open',
]);
$folio = DB::table('reservation_folios')->where('hotel_id', $hotel->id)->where('reservation_record_id', $bookingId)->value('id');
$paymentId = DB::table('folio_payment_records')->insertGetId([
    'hotel_id' => $hotel->id, 'folio_id' => $folio, 'request_key' => 'race-bank-deposit',
    'payload_sha256' => str_repeat('b', 64), 'method' => 'bank_transfer', 'reference' => 'RACE-BANK',
    'amount_minor' => 200000, 'status' => 'staff_confirmed', 'recorded_by' => $actor->id,
    'reviewed_by' => $reviewer->id, 'created_at' => now(), 'updated_at' => now(),
]);
DB::table('folio_settlement_verifications')->insert([
    'hotel_id' => $hotel->id, 'folio_id' => $folio, 'payment_record_id' => $paymentId,
    'evidence_kind' => 'bank_statement', 'evidence_reference' => 'RACE-BANK', 'status' => 'verified',
    'prepared_by' => $actor->id, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
]);
$fingerprint = app(NoShowFinancialReviewService::class)->preview($hotel, $bookingId)['reviewFingerprint'];
$review = app(NoShowFinancialReviewService::class)->apply($hotel, $bookingId, $actor->id, $fingerprint, 'Verified synthetic booking policy for race.');
app(\App\Services\Finance\NoShowFinancialClearanceService::class)->approve(
    $hotel, $bookingId, (int) $review['reviewId'], $reviewer->id, 'non_vat', 'Synthetic test tax treatment only.'
);
if ($mode === 'deposit-cash') {
    DB::table('folio_payment_records')->where('id', $paymentId)->update(['method' => 'cash']);
    $shift = DB::table('cashier_shifts')->insertGetId([
        'hotel_id' => $hotel->id, 'cashier_id' => $actor->id, 'currency' => 'PHP', 'status' => 'reconciled',
        'opening_float_minor' => 50000, 'expected_cash_minor' => 250000, 'counted_cash_minor' => 250000,
        'variance_minor' => 0, 'reviewed_by' => $reviewer->id, 'opened_at' => now(),
        'submitted_at' => now(), 'reconciled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('cashier_shift_receipts')->insert([
        'hotel_id' => $hotel->id, 'shift_id' => $shift, 'payment_id' => $paymentId,
        'reservation_record_id' => $bookingId, 'amount_minor' => 200000,
        'cash_tendered_minor' => 200000, 'change_given_minor' => 0, 'net_receipt_minor' => 200000, 'created_at' => now(),
    ]);
    DB::table('folio_settlement_verifications')->where('payment_record_id', $paymentId)
        ->update(['evidence_kind' => 'cash_shift', 'evidence_reference' => 'shift-'.$shift]);
    $beforeShift = DB::table('cashier_shifts')->find($shift);
    $beforeReceipts = DB::table('cashier_shift_receipts')->where('shift_id', $shift)->get()->all();
}
if (!$isDeposit) {
    app(\App\Services\Finance\GuestDepositJournalService::class)->recognize($hotel, $bookingId, $paymentId, $reviewer->id);
}
$barrier = sys_get_temp_dir().'/pms-allocation-race-'.bin2hex(random_bytes(8));
$workers = [];
try {
    foreach ([1, 2] as $index) {
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $barrier, (string) $index, (string) $hotel->id,
            (string) $bookingId, (string) $paymentId, (string) $reviewer->id, $mode], [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($worker)) throw new RuntimeException('Cannot start worker.');
        $workers[] = $worker;
    }
    $deadline = microtime(true) + 30;
    while ((!file_exists($barrier.'.ready.1') || !file_exists($barrier.'.ready.2')) && microtime(true) < $deadline) usleep(10000);
    if (!file_exists($barrier.'.ready.1') || !file_exists($barrier.'.ready.2')) throw new RuntimeException('Workers not ready.');
    touch($barrier);
    $statuses = array_map('proc_close', $workers);
    $workers = [];
    sort($statuses);
    $report = app(\App\Services\Finance\NoShowLedgerReconciliationService::class)->report($hotel, $bookingId, $reviewer->id);
    $allocations = DB::table('folio_settlement_allocations')->where('hotel_id', $hotel->id)->where('folio_id', $folio);
    if ($isDeposit) {
        $receipts = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
            ->where('source_type', 'guest_deposit_receipt')->where('source_reference', 'folio-payment-'.$paymentId)->get();
        $firstId = @file_get_contents($barrier.'.journal.1');
        $secondId = @file_get_contents($barrier.'.journal.2');
        if ($statuses !== [0, 0] || $receipts->count() !== 1 || $firstId === false || $firstId !== $secondId
            || $firstId !== (string) $receipts->first()->id || (clone $allocations)->exists()
            || !$report['reconciled'] || $report['matchedJournalMinor']['deposits'] !== 200000
            || $report['matchedJournalBalances'] !== ['depositLiabilityMinor' => 200000, 'receivableMinor' => 200000]) {
            throw new RuntimeException('Deposit race failed: '.json_encode([$statuses, $report]));
        }
        if ($mode === 'deposit-cash' && (DB::table('cashier_shifts')->find($shift) != $beforeShift
            || DB::table('cashier_shift_receipts')->where('shift_id', $shift)->get()->all() != $beforeReceipts)) {
            throw new RuntimeException('Cash deposit recognition changed drawer records.');
        }
        echo "$driver passed $mode recognition race.\n";
    } elseif ($statuses !== ($mode === 'duplicate' ? [0, 8] : [0, 9])
        || (clone $allocations)->count() !== 1 || (int) (clone $allocations)->sum('amount_minor') !== 120000
        || DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('source_type', 'no_show_deposit_allocation')->count() !== 1
        || !$report['reconciled'] || $report['expectedBalances'] !== ['depositLiabilityMinor' => 80000, 'receivableMinor' => 80000]) {
        throw new RuntimeException('Allocation race failed: '.json_encode([$statuses, $report]));
    }
    if (!$isDeposit) echo "$driver passed $mode allocation race.\n";
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    }
    foreach ([$barrier, $barrier.'.ready.1', $barrier.'.ready.2', $barrier.'.journal.1', $barrier.'.journal.2'] as $file) if (file_exists($file)) unlink($file);
}

<?php

declare(strict_types=1);

use App\Services\Finance\NoShowFeeCorrectionDecisionService;
use App\Domain\Reservations\NoShowRefundEligibility;
use App\Domain\Reservations\NoShowFinancialReviewService;
use App\Services\Finance\NoShowFinancialClearanceService;
use App\Services\Finance\FinancialBusinessDayCloseService;
use App\Models\HotelMembership;
use Illuminate\Validation\ValidationException;
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

function configureFeeRace(int $hotelId): void
{
    config()->set([
        'booking.no_show_adjustments_enabled' => true,
        'booking.no_show_adjustments_hotel_ids' => (string) $hotelId,
        'financial_accounting.enable_live_folio_tax' => false,
        'financial_accounting.enable_verified_settlement' => true,
        'financial_accounting.enable_guest_deposit_posting' => true,
        'financial_accounting.enable_no_show_allocation_posting' => true,
        'financial_accounting.enable_no_show_fee_correction_review' => true,
        'financial_accounting.enable_no_show_fee_correction_posting' => true,
    ]);
}

if (($argv[1] ?? '') === 'worker') {
    [, , $barrier, $index, $hotelId, $bookingId, $actorId, $proposalId, $mode, $date, $paymentId] = $argv;
    $hotel = Hotel::findOrFail($hotelId);
    configureFeeRace((int) $hotelId);
    touch($barrier.'.ready.'.$index);
    $deadline = microtime(true) + 30;
    while (!file_exists($barrier) && microtime(true) < $deadline) usleep(10000);
    if (!file_exists($barrier)) exit(3);
    $service = app(NoShowFeeCorrectionDecisionService::class);
    try {
        $reason = 'Independently reviewed synthetic fee correction.';
        if ($mode === 'proposal') {
            $service->propose($hotel, (int) $bookingId, (int) $actorId, $index === '1' ? 100000 : 50000, $reason);
        } elseif ($mode === 'approval') {
            $service->approve($hotel, (int) $proposalId, (int) $actorId, 'vat_standard', $reason, $reason);
        } elseif ($mode === 'closing' && $index === '1') {
            app(FinancialBusinessDayCloseService::class)->close((int) $hotelId, $date, (int) $actorId);
            exit(0);
        } elseif ($mode === 'allocation' && $index === '1') {
            app(\App\Domain\Reservations\FolioSettlementService::class)->allocate(
                $hotel, (int) $bookingId, (int) $paymentId, (int) $actorId,
                'fee-race-allocation', 'guest', 'Synthetic guest', 150000, null
            );
        } elseif ($mode === 'refund' && $index === '1') {
            app(\App\Domain\Reservations\RefundRequestService::class)->create($hotel, (int) $bookingId, (int) $actorId, [
                'requestKey' => 'fee-race-refund', 'paymentRecordId' => (int) $paymentId,
                'amountMinor' => 50000, 'reason' => 'Synthetic excess deposit refund request.',
            ]);
        } else {
            $posted = $service->applyApprovedCorrection($hotel, (int) $proposalId, (int) $actorId);
            file_put_contents($barrier.'.result.'.$index, json_encode($posted, JSON_THROW_ON_ERROR));
            exit($posted['replayed'] ? 8 : 0);
        }
        exit(0);
    } catch (HttpException $e) {
        if ($e->getStatusCode() === 409 &&
            (($mode === 'proposal' && $e->getMessage() === 'A fee correction proposal already exists.')
            || ($mode === 'approval' && $e->getMessage() === 'Fee correction already approved.')
            || ($mode === 'closing' && $index === '2' && $e->getMessage() === 'Fee correction evidence changed; reversal blocked.')
            || (in_array($mode, ['allocation', 'refund'], true) && $index === '2' && $e->getMessage() === 'Fee correction requires eligible, reconciled original accounting.'))) exit(9);
        throw $e;
    } catch (ValidationException $e) {
        if ($mode === 'allocation' && $index === '1' && isset($e->errors()['amountMinor'])) exit(9);
        throw $e;
    }
}

$mode = $argv[1] ?? 'posting';
if (!in_array($mode, ['proposal', 'approval', 'posting', 'waiver', 'closing', 'allocation', 'refund'], true)) throw new RuntimeException('Invalid fee race mode.');
$hotel = Hotel::create(['name' => 'No-show concurrency fixture', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
$actor = User::create([
    'name' => 'No-show race staff', 'email' => 'ns-race-'.bin2hex(random_bytes(5)).'@example.test',
    'password' => 'SyntheticRacePassword123!',
]);
$reviewer = User::create([
    'name' => 'Independent room race reviewer', 'email' => 'ns-room-'.bin2hex(random_bytes(5)).'@example.test',
    'password' => 'SyntheticRacePassword123!', 'is_active' => true,
]);
foreach ([$actor, $reviewer] as $staff) {
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id, 'role' => 'admin', 'is_active' => true]);
}
configureFeeRace((int) $hotel->id);
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
$arrival = now('Asia/Manila')->subDays(3)->toDateString();
DB::table('financial_business_clocks')->insert(['hotel_id' => $hotel->id, 'business_date' => $arrival, 'status' => 'open']);
DB::table('financial_accounting_periods')->insert([
    'hotel_id' => $hotel->id, 'starts_on' => now('Asia/Manila')->subDays(3)->startOfMonth()->toDateString(),
    'ends_on' => now('Asia/Manila')->addDay()->endOfMonth()->toDateString(), 'status' => 'open',
]);
$folio = (int) DB::table('reservation_folios')->where('hotel_id', $hotel->id)->where('reservation_record_id', $bookingId)->value('id');
DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => $today]);
app(ReservationBookingService::class)->cancel($hotel, $actor->id, $bookingId, true);
$reviewService = app(NoShowFinancialReviewService::class);
$paymentId = 0;
$deposit = in_array($mode, ['allocation', 'refund'], true) ? 300000 : 0;
if ($deposit > 0) {
    $paymentId = DB::table('folio_payment_records')->insertGetId([
        'hotel_id' => $hotel->id, 'folio_id' => $folio, 'request_key' => 'fee-race-deposit',
        'payload_sha256' => str_repeat('b', 64), 'method' => 'bank_transfer', 'reference' => 'FEE-RACE-BANK',
        'amount_minor' => $deposit, 'status' => 'staff_confirmed', 'recorded_by' => $actor->id,
        'reviewed_by' => $reviewer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('folio_settlement_verifications')->insert([
        'hotel_id' => $hotel->id, 'folio_id' => $folio, 'payment_record_id' => $paymentId,
        'evidence_kind' => 'bank_statement', 'evidence_reference' => 'FEE-RACE-BANK',
        'status' => 'verified', 'prepared_by' => $actor->id, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
    ]);
}
$fingerprint = $reviewService->preview($hotel, $bookingId)['reviewFingerprint'];
$review = $reviewService->apply($hotel, $bookingId, $actor->id, $fingerprint, 'Synthetic race booking policy review.');
app(NoShowFinancialClearanceService::class)->approve($hotel, $bookingId, (int) $review['reviewId'], $reviewer->id,
    'vat_standard', 'Synthetic independently reviewed tax classification.');
if ($deposit > 0) {
    app(\App\Services\Finance\GuestDepositJournalService::class)->recognize($hotel, $bookingId, $paymentId, $reviewer->id);
}
$service = app(NoShowFeeCorrectionDecisionService::class);
$reason = 'Independently reviewed synthetic fee correction.';
$proposal = $mode === 'proposal' ? 0 : $service->propose($hotel, $bookingId, $actor->id, $mode === 'waiver' ? 0 : 100000, $reason);
if (in_array($mode, ['posting', 'waiver', 'closing', 'allocation', 'refund'], true)) {
    $service->approve($hotel, $proposal, $reviewer->id, 'vat_standard', $reason, $reason);
}
$barrier = sys_get_temp_dir().'/pms-fee-correction-race-'.bin2hex(random_bytes(8));
$workers = [];
try {
    foreach ([1, 2] as $index) {
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $barrier, (string) $index, (string) $hotel->id,
            (string) $bookingId, (string) ($mode === 'proposal' ? $actor->id : $reviewer->id), (string) $proposal, $mode, $today, (string) $paymentId], [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($worker)) throw new RuntimeException('Cannot start fee correction worker.');
        $workers[] = $worker;
    }
    $deadline = microtime(true) + 15;
    while ((! file_exists($barrier.'.ready.1') || ! file_exists($barrier.'.ready.2')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! file_exists($barrier.'.ready.1') || ! file_exists($barrier.'.ready.2')) {
        throw new RuntimeException('Both no-show workers must be ready before the concurrent attempt.');
    }
    touch($barrier);
    $statuses = array_map('proc_close', $workers);
    $workers = [];
    sort($statuses);
    $proposals = DB::table('no_show_fee_correction_proposals')->where('hotel_id', $hotel->id)->where('booking_id', $bookingId);
    $proposalId = $proposals->value('id');
    $reviews = DB::table('no_show_fee_correction_reviews')->where('proposal_id', $proposalId);
    $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id);
    $reversals = (clone $journals)->where('source_type', 'no_show_fee_reversal');
    $replacements = (clone $journals)->where('source_type', 'no_show_fee_replacement');
    $credits = DB::table('folio_adjustments')->where('hotel_id', $hotel->id)->where('folio_id', $folio)
        ->where('request_key', 'no-show-fee-correction-'.$proposalId);
    $posted = in_array($mode, ['posting', 'waiver'], true) || ($mode === 'closing' && $statuses === [0, 0]);
    if (in_array($mode, ['allocation', 'refund'], true)) {
        $posted = $reversals->count() === 1;
    }
    $expected = $mode === 'waiver' ? 0 : ($posted ? 100000 : 200000);
    $expectedStatuses = in_array($mode, ['posting', 'waiver'], true) ? [0, 8] : [0, 9];
    if ($mode === 'closing' && $posted) $expectedStatuses = [0, 0];
    if ($mode === 'refund' && $posted) $expectedStatuses = [0, 0];
    $effective = NoShowRefundEligibility::clearance($hotel->id, $bookingId, $folio);
    if ($statuses !== $expectedStatuses || $proposals->count() !== 1
        || $reviews->count() !== ($mode === 'proposal' ? 0 : 1)
        || $reversals->count() !== ($posted ? 1 : 0)
        || $replacements->count() !== ($posted && $expected > 0 ? 1 : 0)
        || $credits->count() !== ($posted ? 1 : 0)
        || (int) $credits->sum('amount_minor') !== ($posted ? 200000 - $expected : 0)
        || $effective === null || (int) $effective->gross_minor !== $expected
        || app(NoShowRefundEligibility::class)->review($hotel, $bookingId)['remainingCollectionMinor'] !== max(0, $expected - $deposit)) {
        throw new RuntimeException('Fee correction race failed: '.json_encode([$mode, $statuses, $effective]));
    }
    if (in_array($mode, ['posting', 'waiver'], true)) {
        $first = json_decode(file_get_contents($barrier.'.result.1'), true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode(file_get_contents($barrier.'.result.2'), true, 512, JSON_THROW_ON_ERROR);
        if ($first['replayed'] === $second['replayed']
            || array_replace($first, ['replayed' => false]) !== array_replace($second, ['replayed' => false])) {
            throw new RuntimeException('Duplicate posting did not return the same correction receipt.');
        }
    }
    if (in_array($mode, ['allocation', 'refund'], true)) {
        $allocations = DB::table('folio_settlement_allocations')->where('hotel_id', $hotel->id)->where('folio_id', $folio);
        $refunds = DB::table('folio_refund_requests')->where('hotel_id', $hotel->id)->where('folio_id', $folio);
        $expectedAllocated = $mode === 'allocation' && !$posted ? 150000 : 0;
        $expectedReserved = $mode === 'refund' ? 50000 : 0;
        $eligibility = app(NoShowRefundEligibility::class)->review($hotel, $bookingId);
        $report = app(\App\Services\Finance\NoShowLedgerReconciliationService::class)->report($hotel, $bookingId, $reviewer->id);
        if ((int) $allocations->sum('amount_minor') !== $expectedAllocated
            || $allocations->count() !== ($expectedAllocated > 0 ? 1 : 0)
            || (int) $refunds->sum('amount_minor') !== $expectedReserved
            || $refunds->count() !== ($expectedReserved > 0 ? 1 : 0)
            || ($expectedReserved > 0 && $refunds->value('status') !== 'requested')
            || $eligibility['verifiedPaymentsMinor'] !== $deposit
            || $eligibility['pendingRefundsMinor'] !== $expectedReserved
            || $eligibility['verifiedRefundsMinor'] !== 0
            || !$report['reconciled']
            || $report['matchedJournalBalances'] !== [
                'depositLiabilityMinor' => $deposit - $expectedAllocated,
                'receivableMinor' => $expected - $expectedAllocated,
            ]) {
            throw new RuntimeException('Fee correction settlement race failed: '.json_encode([$mode, $eligibility, $report]));
        }
    }
    if ($mode === 'closing') {
        $close = DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->where('business_date', $today)->first();
        $next = \Carbon\CarbonImmutable::parse($today)->addDay()->toDateString();
        if ($close === null || $close->status !== 'completed'
            || DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->value('business_date') !== $next) {
            throw new RuntimeException('Daily Closing did not advance the business date exactly once.');
        }
        $snapshot = json_decode($close->reconciliation_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $dayJournals = (clone $journals)->where('business_date', $today);
        $dayLines = DB::table('financial_posting_lines')->whereIn('journal_id', (clone $dayJournals)->select('id'));
        if ((int) $snapshot['journal_count'] !== $dayJournals->count()
            || (int) $snapshot['line_count'] !== $dayLines->count()
            || (int) $snapshot['debits_minor'] !== (int) (clone $dayLines)->sum('debit_minor')
            || (int) $snapshot['credits_minor'] !== (int) (clone $dayLines)->sum('credit_minor')
            || $snapshot['debits_minor'] !== $snapshot['credits_minor']) {
            throw new RuntimeException('Closed journal snapshot changed.');
        }
    }
    echo "$driver passed fee correction $mode race.\n";
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker)) {
            proc_terminate($worker);
            proc_close($worker);
        }
    }
    foreach ([$barrier, $barrier.'.ready.1', $barrier.'.ready.2', $barrier.'.result.1', $barrier.'.result.2'] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

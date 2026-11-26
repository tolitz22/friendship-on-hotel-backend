<?php

declare(strict_types=1);

use App\Services\Finance\NoShowRoomNightCorrectionDecisionService;
use App\Services\Finance\NoShowRoomCorrectionCompletion;
use App\Services\Finance\FolioNightAssessmentService;
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

function configureRoomRace(int $hotelId): void
{
    config()->set([
        'booking.no_show_adjustments_enabled' => true,
        'booking.no_show_adjustments_hotel_ids' => (string) $hotelId,
        'financial_accounting.enable_live_folio_tax' => true,
        'financial_accounting.enable_no_show_room_correction_review' => true,
        'financial_accounting.enable_no_show_room_correction_posting' => true,
    ]);
}

if (($argv[1] ?? '') === 'worker') {
    [, , $barrier, $index, $hotelId, $bookingId, $actorId, $proposalId, $mode, $date] = $argv;
    $hotel = Hotel::findOrFail($hotelId);
    configureRoomRace((int) $hotelId);
    touch($barrier.'.ready.'.$index);
    $deadline = microtime(true) + 30;
    while (!file_exists($barrier) && microtime(true) < $deadline) usleep(10000);
    if (!file_exists($barrier)) exit(3);
    $service = app(NoShowRoomNightCorrectionDecisionService::class);
    try {
        $reason = 'Independently reviewed synthetic room accounting.';
        if ($mode === 'proposal') {
            $service->propose($hotel, (int) $bookingId, (int) $actorId, $reason);
        } elseif ($mode === 'approval') {
            $service->approve($hotel, (int) $proposalId, (int) $actorId, $reason);
        } elseif ($mode === 'closing' && $index === '1') {
            app(FinancialBusinessDayCloseService::class)->close((int) $hotelId, $date, (int) $actorId);
            throw new RuntimeException('Unresolved no-show unexpectedly allowed closing.');
        } else {
            $posted = $service->post($hotel, (int) $proposalId, (int) $actorId);
            file_put_contents($barrier.'.result.'.$index, json_encode($posted, JSON_THROW_ON_ERROR));
            exit($posted['replayed'] ? 8 : 0);
        }
        exit(0);
    } catch (HttpException $e) {
        if ($e->getStatusCode() === 409 &&
            (($mode === 'proposal' && $e->getMessage() === 'A correction proposal already exists.')
            || ($mode === 'approval' && $e->getMessage() === 'Correction already approved.'))) exit(9);
        throw $e;
    } catch (ValidationException $e) {
        if ($mode === 'closing' && $index === '1' && isset($e->errors()['no_show_financial_review'])) exit(9);
        throw $e;
    }
}

$mode = $argv[1] ?? 'posting';
if (!in_array($mode, ['proposal', 'approval', 'posting', 'closing'], true)) throw new RuntimeException('Invalid room race mode.');
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
configureRoomRace((int) $hotel->id);
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
$policy = DB::table('financial_tax_policies')->insertGetId([
    'hotel_id' => $hotel->id, 'classification' => 'vat_standard', 'price_mode' => 'inclusive',
    'rate_bps' => 1200, 'effective_from' => $arrival, 'status' => 'approved',
    'created_by' => $actor->id, 'approved_by' => $reviewer->id, 'approved_at' => now(),
]);
$folio = (int) DB::table('reservation_folios')->where('hotel_id', $hotel->id)->where('reservation_record_id', $bookingId)->value('id');
$entry = (int) DB::table('folio_entries')->where('hotel_id', $hotel->id)->where('folio_id', $folio)
    ->where('entry_type', 'room_night')->orderBy('stay_date')->value('id');
$assessment = app(FolioNightAssessmentService::class);
$draft = $assessment->prepare($hotel->id, $entry, $policy, $actor->id);
$assessment->approve($hotel->id, (int) $draft['id'], $reviewer->id);
$assessment->recognize($hotel->id, (int) $draft['id'], $reviewer->id);
DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => $today]);
app(ReservationBookingService::class)->cancel($hotel, $actor->id, $bookingId, true);
$service = app(NoShowRoomNightCorrectionDecisionService::class);
$reason = 'Independently reviewed synthetic room accounting.';
$proposal = $mode === 'proposal' ? 0 : $service->propose($hotel, $bookingId, $actor->id, $reason);
if (in_array($mode, ['posting', 'closing'], true)) $service->approve($hotel, $proposal, $reviewer->id, $reason);
$barrier = sys_get_temp_dir().'/pms-no-show-race-'.bin2hex(random_bytes(8));
$workers = [];
try {
    foreach ([1, 2] as $index) {
        $workers[] = proc_open([PHP_BINARY, __FILE__, 'worker', $barrier, (string) $index, (string) $hotel->id,
            (string) $bookingId, (string) ($mode === 'proposal' ? $actor->id : $reviewer->id), (string) $proposal, $mode, $today], [STDIN, STDOUT, STDERR], $pipes);
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
    $proposals = DB::table('no_show_room_correction_proposals')->where('hotel_id', $hotel->id)->where('booking_id', $bookingId);
    $proposalId = $proposals->value('id');
    $reviews = DB::table('no_show_room_correction_reviews')->where('proposal_id', $proposalId);
    $reversals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
        ->where('source_type', 'no_show_room_correction');
    $posted = in_array($mode, ['posting', 'closing'], true);
    $expectedStatuses = $mode === 'posting' ? [0, 8] : [0, 9];
    if ($statuses !== $expectedStatuses || $proposals->count() !== 1
        || $reviews->count() !== ($mode === 'proposal' ? 0 : 1)
        || $reversals->count() !== ($posted ? 3 : 0)
        || app(NoShowRoomCorrectionCompletion::class)->valid($hotel->id, $folio) !== $posted
        || DB::table('folio_adjustments')->where('hotel_id', $hotel->id)->where('folio_id', $folio)->exists()
        || DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->where('business_date', $today)->exists()) {
        throw new RuntimeException('Room correction race failed: '.json_encode([$mode, $statuses]));
    }
    if ($mode === 'posting') {
        $first = json_decode(file_get_contents($barrier.'.result.1'), true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode(file_get_contents($barrier.'.result.2'), true, 512, JSON_THROW_ON_ERROR);
        if ($first['journalIds'] !== $second['journalIds'] || $first['replayed'] === $second['replayed']) {
            throw new RuntimeException('Concurrent reversals did not return identical journal IDs.');
        }
    }
    echo "$driver passed room correction $mode race.\n";
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

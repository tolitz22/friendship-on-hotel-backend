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

if (($argv[1] ?? '') === 'worker') {
    [, , $barrier, $index, $hotelId, $bookingId, $actorId, $fingerprint] = $argv;
    $hotel = Hotel::findOrFail($hotelId);
    config()->set(['booking.no_show_adjustments_enabled' => true, 'booking.no_show_adjustments_hotel_ids' => $hotelId]);
    touch($barrier.'.ready.'.$index);
    $deadline = microtime(true) + 15;
    while (! file_exists($barrier) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! file_exists($barrier)) {
        exit(3);
    }
    try {
        app(NoShowFinancialReviewService::class)->apply($hotel, (int) $bookingId, (int) $actorId,
            $fingerprint, 'Verified booking policy for concurrent requests.');
        exit(0);
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() === 409) {
            exit(9);
        }
        throw $exception;
    }
}

$hotel = Hotel::create(['name' => 'No-show concurrency fixture', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
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
app(ReservationBookingService::class)->cancel($hotel, $actor->id, $bookingId, true);
config()->set(['booking.no_show_adjustments_enabled' => true, 'booking.no_show_adjustments_hotel_ids' => (string) $hotel->id]);
$fingerprint = app(NoShowFinancialReviewService::class)->preview($hotel, $bookingId)['reviewFingerprint'];

$barrier = sys_get_temp_dir().'/pms-no-show-race-'.bin2hex(random_bytes(8));
$workers = [];
try {
    foreach ([1, 2] as $index) {
        $workers[] = proc_open([PHP_BINARY, __FILE__, 'worker', $barrier, (string) $index, (string) $hotel->id,
            (string) $bookingId, (string) $actor->id, $fingerprint], [STDIN, STDOUT, STDERR], $pipes);
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
    if ($statuses !== [0, 9]
        || DB::table('no_show_financial_reviews')->where('hotel_id', $hotel->id)->count() !== 1
        || DB::table('folio_adjustments')->where('hotel_id', $hotel->id)->count() !== 2) {
        throw new RuntimeException('The concurrent attempts must produce one review, one reversal and one fee.');
    }
    echo "$driver passed the no-show finance race.\n";
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker)) {
            proc_terminate($worker);
            proc_close($worker);
        }
    }
    foreach ([$barrier, $barrier.'.ready.1', $barrier.'.ready.2'] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

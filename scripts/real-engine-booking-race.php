<?php

declare(strict_types=1);

/**
 * Real concurrent reservation inventory probe for disposable CI databases.
 * Six separate PHP/Laravel processes compete for ONE physical room.
 * Never run this script against staging or production databases.
 */
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
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'friendship_on_hotel_test'
    || ! in_array($driver, ['mysql', 'pgsql'], true)) {
    fwrite(STDERR, "Refusing concurrency probe outside the isolated CI MySQL/PostgreSQL database.\n");
    exit(2);
}

if (($argv[1] ?? null) === 'worker') {
    [$script, $command, $barrier, $hotelId, $actor, $guest, $type, $start, $end, $key] = $argv;
    $deadline = microtime(true) + 15;
    while (! file_exists($barrier) && microtime(true) < $deadline) {
        usleep(5000);
    }
    if (! file_exists($barrier)) {
        fwrite(STDERR, "Worker start barrier timed out.\n");
        exit(3);
    }
    try {
        app(ReservationBookingService::class)->create(
            Hotel::findOrFail((int) $hotelId), (int) $actor,
            ['idempotencyKey' => $key, 'guestId' => (int) $guest,
                'roomTypeId' => (int) $type, 'checkIn' => $start, 'checkOut' => $end]
        );
        echo "created\n";
        exit(0);
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() === 409) {
            echo "inventory_conflict\n";
            exit(0);
        }
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(4);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
        exit(5);
    }
}

$hotel = Hotel::create(['name' => 'Concurrency Probe '.bin2hex(random_bytes(5)),
    'timezone' => 'Asia/Manila']);
$type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Only Room',
    'max_occupancy' => 2]);
Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id,
    'number' => 'RACE-001']);
$staff = User::create(['name' => 'Concurrent CI Actor',
    'email' => 'race-'.bin2hex(random_bytes(7)).'@example.test',
    'password' => 'synthetic-password', 'is_active' => true]);
$guest = DB::table('guests')->insertGetId(['hotel_id' => $hotel->id,
    'first_name' => 'Race', 'last_name' => 'Guest',
    'created_at' => now(), 'updated_at' => now()]);
$start = now('Asia/Manila')->addDays(3)->toDateString();
$end = now('Asia/Manila')->addDays(4)->toDateString();
$barrier = tempnam(sys_get_temp_dir(), 'hotel-race-');
if ($barrier === false) {
    throw new RuntimeException('Could not create race barrier.');
}
unlink($barrier);
$children = [];
for ($i = 0; $i < 6; $i++) {
    $command = [PHP_BINARY, __FILE__, 'worker', $barrier,
        (string) $hotel->id, (string) $staff->id, (string) $guest,
        (string) $type->id, $start, $end, 'race-key-'.bin2hex(random_bytes(8))];
    $pipes = [];
    $process = proc_open($command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not spawn independent booking process.');
    }
    fclose($pipes[0]);
    $children[] = [$process, $pipes];
}
touch($barrier);

$outcomes = [];
$errors = [];
foreach ($children as [$process, $pipes]) {
    $stdout = trim(stream_get_contents($pipes[1]));
    $stderr = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || ! in_array($stdout, ['created', 'inventory_conflict'], true)) {
        $errors[] = "exit={$exit}; output={$stdout}; error={$stderr}";
    }
    $outcomes[] = $stdout;
}
@unlink($barrier);
$persisted = DB::table('reservation_records')->where('hotel_id', $hotel->id)->count();
$successes = count(array_filter($outcomes, fn ($status) => $status === 'created'));
if ($errors !== [] || $successes !== 1 || $persisted !== 1) {
    fwrite(STDERR, json_encode(['errors' => $errors, 'outcomes' => $outcomes,
        'persisted' => $persisted], JSON_PRETTY_PRINT)."\n");
    exit(1);
}
echo "{$driver}: six independent writers, one successful booking, five conflicts; no overbooking.\n";

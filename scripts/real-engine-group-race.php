<?php

declare(strict_types=1);

/**
 * Two independent Laravel processes submit competing TWO-room group requests
 * against two rooms of the same type and date range.
 *
 * Run only after migrations in disposable friendship_on_hotel_test on MySQL 8.4
 * or PostgreSQL: APP_ENV=testing php scripts/real-engine-group-race.php
 * No production or staging database is permitted.
 */
use App\Domain\Reservations\ReservationBookingService;
use App\Http\Controllers\Api\GroupReservationController;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$driver = DB::getDriverName();
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'friendship_on_hotel_test'
    || ! in_array($driver, ['mysql', 'pgsql'], true)) {
    fwrite(STDERR, "Refusing group race outside the disposable CI MySQL/PostgreSQL database.\n");
    exit(2);
}

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $barrier, $hotelId, $actorId, $typeId,
        $guestA, $guestB, $start, $end, $key] = $argv;
    // Never inherit a connection or transaction from a parent process.
    DB::disconnect();
    file_put_contents($barrier.'.ready.'.$key, 'ready');
    $deadline = microtime(true) + 30;
    while (! file_exists($barrier) && microtime(true) < $deadline) usleep(5000);
    if (! file_exists($barrier)) exit(3);
    try {
        $request = Request::create('/api/v1/hotels/'.$hotelId.'/group-reservations', 'POST', [
            'idempotencyKey' => $key, 'name' => 'Concurrent group '.$key,
            'contactName' => 'CI tester', 'checkIn' => $start, 'checkOut' => $end,
            'rooms' => [
                ['guestId' => (int) $guestA, 'roomTypeId' => (int) $typeId],
                ['guestId' => (int) $guestB, 'roomTypeId' => (int) $typeId],
            ],
        ]);
        $request->setUserResolver(static fn () => User::findOrFail((int) $actorId));
        app(GroupReservationController::class)->store($request,
            Hotel::findOrFail((int) $hotelId), app(ReservationBookingService::class));
        echo "created\n";
        exit(0);
    } catch (HttpException $e) {
        if ($e->getStatusCode() === 409) {
            echo "inventory_conflict\n";
            exit(0);
        }
        fwrite(STDERR, $e->getMessage()."\n");
        exit(4);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(5);
    }
}

$hotel = Hotel::create(['name' => 'Group Race '.bin2hex(random_bytes(5)),
    'timezone' => 'Asia/Manila']);
$type = RoomType::create(['hotel_id' => $hotel->id,
    'name' => 'Group Race Standard', 'max_occupancy' => 2]);
for ($i = 1; $i <= 2; $i++) Room::create(['hotel_id' => $hotel->id,
    'room_type_id' => $type->id, 'number' => 'GRACE-'.$i]);
$actor = User::create(['name' => 'Group Race Actor',
    'email' => 'group-race-'.bin2hex(random_bytes(6)).'@example.test',
    'password' => 'synthetic-password', 'is_active' => true]);
$guests = [];
foreach (range(1, 4) as $i) {
    $guests[] = DB::table('guests')->insertGetId([
        'hotel_id' => $hotel->id, 'first_name' => 'Concurrent'.$i,
        'last_name' => 'Guest', 'created_at' => now(), 'updated_at' => now(),
    ]);
}
$start = now('Asia/Manila')->addDays(3)->toDateString();
$end = now('Asia/Manila')->addDays(4)->toDateString();
$barrier = tempnam(sys_get_temp_dir(), 'hotel-group-race-');
if ($barrier === false) throw new RuntimeException('Cannot create start barrier');
unlink($barrier);
$children = [];
foreach ([[0,1],[2,3]] as $indexes) {
    $key = 'group-race-'.bin2hex(random_bytes(8));
    $args = [PHP_BINARY, __FILE__, 'worker', $barrier, (string) $hotel->id,
        (string) $actor->id, (string) $type->id,
        (string) $guests[$indexes[0]], (string) $guests[$indexes[1]],
        $start, $end, $key];
    $pipes = [];
    $process = proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (! is_resource($process)) throw new RuntimeException('Cannot launch competing group request');
    fclose($pipes[0]);
    $children[] = [$process, $pipes];
}
$deadline = microtime(true) + 30;
while (count(glob($barrier.'.ready.*') ?: []) !== 2 && microtime(true) < $deadline) {
    usleep(10000);
}
if (count(glob($barrier.'.ready.*') ?: []) !== 2) {
    foreach ($children as [$process, $pipes]) {
        proc_terminate($process);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
    foreach (glob($barrier.'.ready.*') ?: [] as $file) @unlink($file);
    fwrite(STDERR, "Workers failed to reach the synchronized start barrier.\n");
    exit(3);
}
touch($barrier);
$outcomes = [];
$errors = [];
foreach ($children as [$process, $pipes]) {
    $stdout = trim(stream_get_contents($pipes[1]));
    $stderr = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    $outcomes[] = $stdout;
    if ($code !== 0 || ! in_array($stdout, ['created','inventory_conflict'], true))
        $errors[] = ['exit' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}
@unlink($barrier);
foreach (glob($barrier.'.ready.*') ?: [] as $file) @unlink($file);
$groups = DB::table('group_reservations')->where('hotel_id', $hotel->id)->count();
$bookings = DB::table('reservation_records')->where('hotel_id', $hotel->id)->count();
$members = DB::table('group_reservation_members')->where('hotel_id', $hotel->id)->count();
$requests = DB::table('reservation_booking_requests')->where('hotel_id', $hotel->id)->count();
if ($errors !== [] || count(array_filter($outcomes, fn ($x) => $x === 'created')) !== 1
    || count(array_filter($outcomes, fn ($x) => $x === 'inventory_conflict')) !== 1
    || $groups !== 1 || $bookings !== 2 || $members !== 2 || $requests !== 2) {
    fwrite(STDERR, json_encode(compact('errors','outcomes','groups','bookings','members','requests'),
        JSON_PRETTY_PRINT)."\n");
    exit(1);
}
echo "{$driver}: competing two-room group requests: one full group created, one conflict; zero partial groups/overbooking.\n";

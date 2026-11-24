<?php
declare(strict_types=1);

use App\Models\Hotel;
use App\Models\User;
use App\Models\HotelMembership;
use App\Services\Finance\SupplierDirectoryService;
use App\Services\Finance\SupplierBillPostingService;
use App\Services\Finance\SupplierBillPaymentService;
use App\Services\Finance\SupplierPayableAging;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$driver = DB::getDriverName();
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'friendship_on_hotel_test'
    || DB::connection()->getDatabaseName() !== 'friendship_on_hotel_test'
    || !in_array($driver, ['mysql', 'pgsql'], true)) {
    fwrite(STDERR, "Disposable CI database required.\n");
    exit(2);
}
config(['financial_accounting.enable_supplier_billing' => true]);
if (($argv[1] ?? '') === 'worker') {
    [, , $barrier, $hotelId, $actorId, $billId, $key] = $argv;
    DB::disconnect();
    file_put_contents($barrier.'.ready.'.$key, 'ready');
    $deadline = microtime(true) + 30;
    while (!file_exists($barrier) && microtime(true) < $deadline) usleep(5000);
    if (!file_exists($barrier)) exit(3);
    try {
        app(SupplierBillPaymentService::class)->record(Hotel::findOrFail((int) $hotelId), (int) $actorId, (int) $billId, [
            'requestKey' => $key, 'amountMinor' => 300000, 'paymentSource' => 'hotel_bank', 'evidenceReference' => $key,
        ]);
        echo "created\n";
        exit(0);
    } catch (HttpException $e) {
        if ($e->getStatusCode() === 409 && str_contains($e->getMessage(), 'exceeds the outstanding')) {
            echo "overpayment_conflict\n";
            exit(0);
        }
        fwrite(STDERR, $e->getMessage()."\n");
        exit(4);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(5);
    }
}
$hotel = Hotel::create(['name' => 'Supplier race '.bin2hex(random_bytes(5)), 'currency' => 'PHP']);
$actors = [];
foreach (range(1, 2) as $i) {
    $user = User::create(['name' => 'Supplier race '.$i, 'email' => 'supplier-race-'.bin2hex(random_bytes(6)).'@example.test', 'password' => 'synthetic-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    $actors[] = $user;
}
$date = '2026-11-24';
DB::table('financial_business_clocks')->insert(['hotel_id' => $hotel->id, 'business_date' => $date, 'status' => 'open']);
DB::table('financial_accounting_periods')->insert(['hotel_id' => $hotel->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'open']);
$supplier = app(SupplierDirectoryService::class)->save($hotel, $actors[0]->id, null, ['name' => 'Race Laundry', 'paymentTermsDays' => 0, 'isActive' => true]);
$bill = app(SupplierBillPostingService::class)->record($hotel, $actors[0]->id, [
    'requestKey' => 'race-invoice-65', 'supplierId' => $supplier['id'], 'invoiceReference' => 'RACE-65',
    'description' => 'Laundry', 'department' => 'rooms', 'category' => 'laundry', 'invoiceDate' => $date,
    'serviceDate' => $date, 'dueDate' => $date, 'amountMinor' => 480000,
]);
$barrier = tempnam(sys_get_temp_dir(), 'supplier-race-');
if ($barrier === false) throw new RuntimeException('Cannot create barrier');
unlink($barrier);
$children = [];
$outcomes = [];
try {
    foreach ($actors as $actor) {
        $key = 'supplier-race-'.bin2hex(random_bytes(8));
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $barrier, (string) $hotel->id, (string) $actor->id, $bill['billId'], $key],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start worker');
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 30;
    while (count(glob($barrier.'.ready.*') ?: []) !== 2 && microtime(true) < $deadline) usleep(10000);
    if (count(glob($barrier.'.ready.*') ?: []) !== 2) throw new RuntimeException('Workers did not reach barrier');
    touch($barrier);
    foreach ($children as $index => [$process, $pipes]) {
        stream_set_timeout($pipes[1], 40);
        $stdout = trim(stream_get_contents($pipes[1]));
        if (stream_get_meta_data($pipes[1])['timed_out']) throw new RuntimeException('Worker timed out');
        $stderr = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        unset($children[$index]);
        if ($code !== 0) throw new RuntimeException('Worker failed: '.$stderr);
        $outcomes[] = $stdout;
    }
} finally {
    foreach ($children as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
    }
    @unlink($barrier);
    foreach (glob($barrier.'.ready.*') ?: [] as $file) @unlink($file);
}
sort($outcomes);
$payments = DB::table('supplier_bill_payments')->where('hotel_id', $hotel->id);
$report = app(SupplierPayableAging::class)->report($hotel->id, $date);
if ($outcomes !== ['created','overpayment_conflict'] || (clone $payments)->count() !== 1
    || (int) (clone $payments)->sum('amount_minor') !== 300000
    || DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->count() !== 2
    || $report['outstandingMinor'] !== 180000 || !$report['reconciled']) {
    throw new RuntimeException('Unexpected supplier race results: '.json_encode([$outcomes,$report]));
}
echo "{$driver}: one payment accepted, one overpayment rejected; payable and ledger reconcile.\n";

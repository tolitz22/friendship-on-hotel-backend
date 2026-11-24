<?php
declare(strict_types=1);

use App\Models\Hotel;
use App\Models\User;
use App\Models\HotelMembership;
use App\Services\Finance\EmployeeExpenseClaimService;
use App\Services\Finance\EmployeeExpenseClaimDecisionService;
use App\Services\Finance\EmployeeReimbursementPaymentService;
use App\Services\Finance\EmployeePayableReport;
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
config(['financial_accounting.enable_employee_reimbursements' => true]);
Illuminate\Support\Carbon::setTestNow('2026-11-25 12:00:00');
if (($argv[1] ?? '') === 'worker') {
    [, , $barrier, $hotelId, $actorId, $claimId, $key] = $argv;
    DB::disconnect();
    file_put_contents($barrier.'.ready.'.$key, 'ready');
    $deadline = microtime(true) + 30;
    while (!file_exists($barrier) && microtime(true) < $deadline) usleep(5000);
    if (!file_exists($barrier)) exit(3);
    try {
        app(EmployeeReimbursementPaymentService::class)->record(Hotel::findOrFail((int) $hotelId), (int) $actorId, (int) $claimId, [
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
$hotel = Hotel::create(['name' => 'Employee race '.bin2hex(random_bytes(5)), 'currency' => 'PHP']);
$actors = [];
foreach (range(1, 2) as $i) {
    $user = User::create(['name' => 'Employee race '.$i, 'email' => 'employee-race-'.bin2hex(random_bytes(6)).'@example.test', 'password' => 'synthetic-password', 'is_active' => true]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);
    $actors[] = $user;
}
$date = '2026-11-24';
DB::table('financial_business_clocks')->insert(['hotel_id' => $hotel->id, 'business_date' => $date, 'status' => 'open']);
DB::table('financial_accounting_periods')->insert(['hotel_id' => $hotel->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'open']);
$employee = User::create([
    'name' => 'Employee claimant', 'email' => 'claimant-'.bin2hex(random_bytes(6)).'@example.test',
    'password' => 'synthetic-password', 'is_active' => true,
]);
HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $employee->id, 'role' => 'housekeeping', 'is_active' => true]);
$claim = app(EmployeeExpenseClaimService::class)->submit($hotel, $employee->id, [
    'requestKey' => 'race-claim-65', 'merchant' => 'Race Laundry', 'description' => 'Laundry',
    'department' => 'rooms', 'category' => 'laundry', 'receiptReference' => 'RACE-65',
    'serviceDate' => $date, 'amountMinor' => 480000,
]);
app(EmployeeExpenseClaimDecisionService::class)->decide($hotel, $actors[0]->id, (int) $claim['claimId'], [
    'requestKey' => 'race-decision-65', 'decision' => 'approve', 'note' => 'Receipt checked',
]);
$paymentDate = '2026-11-25';
DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => $paymentDate]);
$barrier = tempnam(sys_get_temp_dir(), 'employee-race-');
if ($barrier === false) throw new RuntimeException('Cannot create barrier');
unlink($barrier);
$children = [];
$outcomes = [];
try {
    foreach ($actors as $actor) {
        $key = 'employee-race-'.bin2hex(random_bytes(8));
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $barrier, (string) $hotel->id, (string) $actor->id, $claim['claimId'], $key],
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
$payments = DB::table('employee_reimbursement_payments')->where('hotel_id', $hotel->id);
$reports = app(EmployeePayableReport::class);
$historical = $reports->report($hotel->id, $date);
$report = $reports->report($hotel->id, $paymentDate);
if ($outcomes !== ['created','overpayment_conflict'] || (clone $payments)->count() !== 1
    || (int) (clone $payments)->sum('amount_minor') !== 300000
    || DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->count() !== 2
    || $historical['outstandingMinor'] !== 480000 || !$historical['reconciled']
    || $report['outstandingMinor'] !== 180000 || !$report['reconciled']) {
    throw new RuntimeException('Unexpected reimbursement race results: '.json_encode([$outcomes,$historical,$report]));
}
echo "{$driver}: one payment accepted, one overpayment rejected; payable and ledger reconcile.\n";

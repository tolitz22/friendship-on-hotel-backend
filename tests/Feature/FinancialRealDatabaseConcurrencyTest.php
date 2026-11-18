<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Requires an isolated MySQL/InnoDB or PostgreSQL TEST database.
 *
 * Run FINANCE_REAL_DB_LOCK_TESTS=1 php artisan test --filter=FinancialRealDatabaseConcurrency
 * with DB_CONNECTION=mysql or pgsql and a dedicated disposable DB_DATABASE.
 *
 * This verifies actual two-connection row-lock exclusion, not a simulated
 * SQLite lock. No production data or fixtures are accessed.
 */
final class FinancialRealDatabaseConcurrencyTest extends TestCase
{
    public function test_second_connection_cannot_acquire_a_held_financial_clock_row_lock(): void
    {
        $driver = DB::connection()->getDriverName();
        if (env('FINANCE_REAL_DB_LOCK_TESTS') !== '1' || !in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Opt-in dedicated MySQL/PostgreSQL lock test only.');
        }
        if (!app()->environment('testing')) {
            self::fail('Real database concurrency probes require APP_ENV=testing.');
        }
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if ($database === '' || !preg_match('/(?:test|ci|staging|sandbox)/i', $database)) {
            self::fail('Refusing to create test tables outside a clearly named isolated test database.');
        }

        $table = 'finance_lock_probe_'.bin2hex(random_bytes(5));
        $name = config('database.default');
        config(['database.connections.finance_lock_peer' => config('database.connections.'.$name)]);
        DB::purge('finance_lock_peer');
        try {
            Schema::create($table, function (Blueprint $schema): void {
                $schema->id();
                $schema->unsignedInteger('version')->default(0);
            });
            DB::table($table)->insert(['id' => 1, 'version' => 0]);
            $first = DB::connection($name);
            $second = DB::connection('finance_lock_peer');
            if ($driver === 'mysql') {
                $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
            } else {
                $second->statement("SET lock_timeout TO '1000ms'");
            }

            $first->beginTransaction();
            try {
                $first->table($table)->where('id', 1)->lockForUpdate()->first();
                $second->beginTransaction();
                try {
                    try {
                        $second->table($table)->where('id', 1)->lockForUpdate()->first();
                        self::fail('Second connection unexpectedly acquired the held row lock.');
                    } catch (QueryException) {
                        // Expected real-engine lock timeout/deadlock-protection error.
                        self::assertTrue(true);
                    }
                } finally {
                    if ($second->transactionLevel() > 0) {
                        $second->rollBack();
                    }
                }
                $first->table($table)->where('id', 1)->increment('version');
                $first->commit();
            } finally {
                if ($first->transactionLevel() > 0) {
                    $first->rollBack();
                }
            }
            self::assertSame(1, (int) $second->table($table)->where('id', 1)->value('version'));
        } finally {
            DB::disconnect('finance_lock_peer');
            Schema::dropIfExists($table);
        }
    }
}

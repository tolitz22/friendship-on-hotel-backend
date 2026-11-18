<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('group_allotments')) {
            return;
        }

        // In certain MySQL/MariaDB configurations a TIMESTAMP column
        // receives an implicit ON UPDATE CURRENT_TIMESTAMP. The expiration
        // deadline must not change when the hold is released or converted.
        // Use a DATETIME instead: it has no implicit automatic update.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE group_allotments MODIFY COLUMN expires_at DATETIME NOT NULL');
        } elseif (DB::getDriverName() === 'pgsql') {
            // PostgreSQL TIMESTAMP has no implicit ON UPDATE clause; still
            // remove any unintended default to preserve write-once intent.
            DB::statement('ALTER TABLE group_allotments ALTER COLUMN expires_at DROP DEFAULT');
        }
        // SQLite datetime storage has no implicit ON UPDATE rule.
    }

    public function down(): void
    {
        // Intentionally no-op. Restoring the faulty implicit ON UPDATE would
        // silently alter historical hold deadlines.
    }
};

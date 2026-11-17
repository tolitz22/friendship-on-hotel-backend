<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair SQLite reservation date guards for databases that already ran
 * the Phase 20 guest_id ALTER TABLE migration before its trigger fix.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('reservation_records')) {
            return;
        }

        // Only alter the two known date-validation triggers. Idempotent so
        // fresh databases and previously migrated local SQLite DBs both work.
        DB::statement('DROP TRIGGER IF EXISTS reservation_period_insert');
        DB::statement('DROP TRIGGER IF EXISTS reservation_period_update');
        DB::statement("CREATE TRIGGER reservation_period_insert BEFORE INSERT ON reservation_records WHEN NEW.check_out <= NEW.check_in BEGIN SELECT RAISE(ABORT, 'departure must follow arrival'); END");
        DB::statement("CREATE TRIGGER reservation_period_update BEFORE UPDATE ON reservation_records WHEN NEW.check_out <= NEW.check_in BEGIN SELECT RAISE(ABORT, 'departure must follow arrival'); END");
    }

    public function down(): void
    {
        // These are foundational integrity guards from the original
        // reservation migration. Rolling back this repair must NOT remove them.
    }
};

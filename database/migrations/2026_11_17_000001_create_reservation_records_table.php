<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservation_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('reference', 48);
            $table->foreignId('room_type_id');
            $table->foreignId('assigned_room_id')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->string('lifecycle_state', 48)->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'reference']);
            $table->index(['hotel_id', 'check_in', 'check_out'], 'res_hotel_stay_idx');
            $table->index(['hotel_id', 'assigned_room_id', 'check_in', 'check_out'], 'res_room_stay_idx');
            $table->foreign(['hotel_id', 'room_type_id'])
                ->references(['hotel_id', 'id'])->on('room_types')->restrictOnDelete();
            $table->foreign(['hotel_id', 'room_type_id', 'assigned_room_id'], 'reservation_room_type_match')
                ->references(['hotel_id', 'room_type_id', 'id'])->on('rooms')->restrictOnDelete();
        });

        // MySQL 8.0.16+ and PostgreSQL enforce CHECK; SQLite uses triggers because its ALTER TABLE cannot add CHECK constraints.
        if (in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
            DB::statement('ALTER TABLE reservation_records ADD CONSTRAINT reservation_period_positive CHECK (check_out > check_in)');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER reservation_period_insert BEFORE INSERT ON reservation_records WHEN NEW.check_out <= NEW.check_in BEGIN SELECT RAISE(ABORT, 'departure must follow arrival'); END");
            DB::statement("CREATE TRIGGER reservation_period_update BEFORE UPDATE ON reservation_records WHEN NEW.check_out <= NEW.check_in BEGIN SELECT RAISE(ABORT, 'departure must follow arrival'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_records');
    }
};

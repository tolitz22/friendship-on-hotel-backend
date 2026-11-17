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
            $table->index(['hotel_id', 'check_in', 'check_out']);
            $table->index(['hotel_id', 'assigned_room_id', 'check_in', 'check_out']);
            $table->foreign(['hotel_id', 'room_type_id'])
                ->references(['hotel_id', 'id'])->on('room_types')->restrictOnDelete();
            $table->foreign(['hotel_id', 'assigned_room_id'])
                ->references(['hotel_id', 'id'])->on('rooms')->restrictOnDelete();
        });

        // Date order is a structural invariant, independent of any booking policy.
        DB::statement('ALTER TABLE reservation_records ADD CONSTRAINT reservation_period_positive CHECK (check_out > check_in)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_records');
    }
};

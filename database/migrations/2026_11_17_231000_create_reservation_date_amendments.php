<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservation_date_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_record_id')->constrained('reservation_records')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->date('old_check_in');
            $table->date('old_check_out');
            $table->date('new_check_in');
            $table->date('new_check_out');
            $table->timestamps();
            $table->index(['hotel_id', 'reservation_record_id'], 'amendment_hotel_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_date_amendments');
    }
};

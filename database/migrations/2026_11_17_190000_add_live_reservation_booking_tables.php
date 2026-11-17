<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reservation_records', function (Blueprint $table): void {
            $table->foreignId('guest_id')->nullable()
                ->after('room_type_id')->constrained('guests')->restrictOnDelete();
        });

        Schema::create('reservation_booking_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('payload_sha256', 64);
            $table->foreignId('reservation_record_id')
                ->constrained('reservation_records')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'idempotency_key'], 'booking_requests_hotel_key_unique');
        });

        Schema::create('reservation_booking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_record_id')
                ->constrained('reservation_records')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->string('old_state', 48)->nullable();
            $table->string('new_state', 48);
            $table->timestamps();
            $table->index(['hotel_id', 'reservation_record_id'], 'booking_events_hotel_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_booking_events');
        Schema::dropIfExists('reservation_booking_requests');
        Schema::table('reservation_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('guest_id');
        });
    }
};

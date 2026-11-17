<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('keycard_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('reservation_record_id')->nullable()->constrained('reservation_records')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->string('kind', 32);
            $table->string('status', 24);
            $table->string('provider', 32)->default('mock');
            $table->string('provider_reference', 100)->nullable();
            $table->text('request_reason');
            $table->text('review_note')->nullable();
            $table->text('revocation_note')->nullable();
            $table->date('valid_from');
            $table->date('valid_until');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'idempotency_key'], 'keycard_idempotency_unique');
            $table->index(['hotel_id', 'status', 'id'], 'keycard_inbox_idx');
            $table->index(['hotel_id', 'reservation_record_id', 'id'], 'keycard_booking_idx');
        });
        Schema::create('keycard_authorization_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorization_id')->constrained('keycard_authorizations')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->text('details');
            $table->timestamp('created_at');
            $table->index(['hotel_id', 'authorization_id', 'id'], 'keycard_event_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('keycard_authorization_events');
        Schema::dropIfExists('keycard_authorizations');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('manager_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_record_id')->constrained('reservation_records')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->char('payload_hash', 64);
            $table->string('action', 32);
            $table->string('status', 24)->default('pending');
            $table->text('reason');
            $table->text('request_payload');
            $table->text('booking_snapshot');
            $table->text('review_note')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'request_key'], 'approval_request_key_unique');
            $table->index(['hotel_id', 'status', 'id'], 'approval_inbox_index');
            $table->index(['hotel_id', 'reservation_record_id', 'id'], 'approval_booking_index');
        });

        Schema::create('manager_approval_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('approval_request_id')->constrained('manager_approval_requests')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 24);
            $table->text('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['hotel_id', 'approval_request_id', 'id'], 'approval_events_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_approval_events');
        Schema::dropIfExists('manager_approval_requests');
    }
};

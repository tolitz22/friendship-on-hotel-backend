<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('occupancy_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('observed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('inspection_date');
            $table->string('status', 24)->default('assigned');
            $table->string('observed_occupancy', 24)->nullable();
            $table->string('expected_occupancy', 24)->nullable();
            $table->string('discrepancy', 32)->nullable();
            $table->text('expected_booking_ids')->nullable();
            $table->text('observation_note')->nullable();
            $table->text('review_note')->nullable();
            $table->string('review_decision', 24)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'room_id', 'inspection_date'], 'occupancy_daily_room_unique');
            $table->index(['hotel_id', 'status', 'id'], 'occupancy_review_inbox_idx');
            $table->index(['hotel_id', 'assigned_user_id', 'status'], 'occupancy_verifier_idx');
        });
        Schema::create('occupancy_verification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('verification_id')->constrained('occupancy_verifications')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->text('details');
            $table->timestamp('created_at');
            $table->index(['hotel_id', 'verification_id', 'id'], 'occupancy_event_lookup');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('occupancy_verification_events');
        Schema::dropIfExists('occupancy_verifications');
    }
};

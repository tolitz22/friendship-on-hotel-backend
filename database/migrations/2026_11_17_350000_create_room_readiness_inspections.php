<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('room_readiness_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 24)->default('submitted'); // submitted, approved, rejected
            $table->boolean('cleaning_verified');
            $table->boolean('safety_verified');
            $table->boolean('amenities_verified');
            $table->string('inspector_note', 2000);
            $table->foreignId('inspected_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_note', 2000)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'room_id', 'id'], 'readiness_inspection_room_idx');
        });

        Schema::create('room_readiness_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('inspection_id')->constrained('room_readiness_inspections')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->text('details_json');
            $table->timestamp('created_at');
            $table->index(['hotel_id', 'room_id', 'id'], 'readiness_events_room_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_readiness_events');
        Schema::dropIfExists('room_readiness_inspections');
    }
};

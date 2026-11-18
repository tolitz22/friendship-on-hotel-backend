<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('occupancy_investigations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('verification_id')->constrained('occupancy_verifications')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('open');
            $table->string('resolution', 48)->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'verification_id'], 'occ_investigation_once');
            $table->index(['hotel_id', 'status', 'owner_id'], 'occ_investigation_queue');
        });

        Schema::create('occupancy_investigation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('investigation_id')->constrained('occupancy_investigations')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->text('details');
            $table->timestamp('created_at');
            $table->index(['hotel_id', 'investigation_id', 'id'], 'occ_investigation_audit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupancy_investigation_events');
        Schema::dropIfExists('occupancy_investigations');
    }
};

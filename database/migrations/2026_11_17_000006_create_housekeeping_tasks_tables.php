<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Relies on the rooms(hotel_id,id) unique index from Phase 13.
        Schema::create('housekeeping_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id');
            $table->string('type', 30);
            $table->string('status', 24)->default('todo');
            $table->string('assigned_to', 120)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['hotel_id','room_id'])->references(['hotel_id','id'])->on('rooms')->restrictOnDelete();
            $table->index(['hotel_id','status','due_at']);
        });
        Schema::create('housekeeping_task_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained('housekeeping_tasks')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['hotel_id','task_id','id'], 'housekeeping_task_event_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_task_events');
        Schema::dropIfExists('housekeeping_tasks');
    }
};

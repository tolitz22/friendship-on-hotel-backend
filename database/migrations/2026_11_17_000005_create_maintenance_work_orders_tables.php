<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('maintenance_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id');
            $table->string('title', 160);
            $table->text('description');
            $table->string('priority', 12);
            $table->string('status', 20)->default('open');
            $table->string('assigned_to', 120)->nullable();
            $table->boolean('affects_room')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->foreign(['hotel_id','room_id'])->references(['hotel_id','id'])->on('rooms')->restrictOnDelete();
            $table->index(['hotel_id','status','created_at']);
        });

        Schema::create('maintenance_work_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('maintenance_work_orders')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['hotel_id','work_order_id','id'], 'maintenance_order_events_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_order_events');
        Schema::dropIfExists('maintenance_work_orders');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_staff_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->timestamp('created_at');
            $table->index(['hotel_id','target_user_id','id'], 'hotel_staff_events_scope_idx');
        });
        Schema::table('housekeeping_tasks', function (Blueprint $table): void {
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['hotel_id','assigned_user_id','status'], 'hk_assigned_staff_idx');
        });
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['hotel_id','assigned_user_id','status'], 'mnt_assigned_staff_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('hotel_staff_events');
        Schema::table('housekeeping_tasks', function (Blueprint $table): void {
            $table->dropIndex('hk_assigned_staff_idx');
            $table->dropConstrainedForeignId('assigned_user_id');
        });
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropIndex('mnt_assigned_staff_idx');
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};

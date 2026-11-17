<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
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

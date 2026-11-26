<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->boolean('housekeeping_completed')->default(true);
            $table->boolean('room_inspection_approved')->default(true);
            $table->boolean('room_inspection_rejected')->default(true);
            $table->boolean('maintenance_created')->default(true);
            $table->boolean('maintenance_urgent')->default(true);
            $table->boolean('maintenance_resolved')->default(true);
            $table->boolean('room_blocked')->default(true);
            $table->boolean('room_unblocked')->default(true);
            $table->boolean('approval_requested')->default(true);
            $table->boolean('approval_approved')->default(true);
            $table->boolean('approval_rejected')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->dropColumn(['housekeeping_completed', 'room_inspection_approved', 'room_inspection_rejected', 'maintenance_created', 'maintenance_urgent', 'maintenance_resolved', 'room_blocked', 'room_unblocked', 'approval_requested', 'approval_approved', 'approval_rejected']);
        });
    }
};

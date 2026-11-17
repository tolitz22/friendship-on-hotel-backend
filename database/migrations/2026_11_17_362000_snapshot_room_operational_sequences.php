<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('room_readiness_inspections', function (Blueprint $table): void {
            // Old approvals conservatively require a new inspection after
            // migration if historical room work exists.
            $table->unsignedBigInteger('latest_housekeeping_task_id')->default(0);
            $table->unsignedBigInteger('latest_room_impact_order_id')->default(0);
            $table->unsignedBigInteger('latest_inventory_block_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('room_readiness_inspections', function (Blueprint $table): void {
            $table->dropColumn([
                'latest_housekeeping_task_id',
                'latest_room_impact_order_id',
                'latest_inventory_block_id',
            ]);
        });
    }
};

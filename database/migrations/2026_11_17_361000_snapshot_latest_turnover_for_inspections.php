<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('room_readiness_inspections', function (Blueprint $table): void {
            // Exact turnover sequence at inspection time; avoids wall-clock
            // second-level timestamp ties after a subsequent checkout.
            $table->unsignedBigInteger('latest_turnover_link_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('room_readiness_inspections', function (Blueprint $table): void {
            $table->dropColumn('latest_turnover_link_id');
        });
    }
};

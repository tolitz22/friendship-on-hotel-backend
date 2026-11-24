<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('room_rate_rules', function (Blueprint $table): void {
            $table->text('weekend_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_rate_rules', function (Blueprint $table): void {
            $table->dropColumn('weekend_days');
        });
    }
};

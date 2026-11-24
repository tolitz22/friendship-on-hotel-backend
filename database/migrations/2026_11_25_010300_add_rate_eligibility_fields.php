<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('room_rate_rules', function (Blueprint $table): void {
            $table->string('eligibility_code', 40)->nullable();
            $table->unsignedTinyInteger('minimum_nights')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_rate_rules', function (Blueprint $table): void {
            $table->dropColumn(['eligibility_code', 'minimum_nights']);
        });
    }
};

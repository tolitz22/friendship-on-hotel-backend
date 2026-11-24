<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('room_rate_rules', function (Blueprint $table): void {
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('retired_by')->nullable();
            $table->unsignedBigInteger('replaces_rule_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_rate_rules', function (Blueprint $table): void {
            $table->dropColumn(['retired_at', 'retired_by', 'replaces_rule_id']);
        });
    }
};

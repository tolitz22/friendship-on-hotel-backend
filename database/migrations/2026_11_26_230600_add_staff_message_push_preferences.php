<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->boolean('staff_message')->default(true);
            $table->boolean('staff_announcement')->default(true);
            $table->boolean('staff_handover')->default(true);
            $table->boolean('staff_acknowledged')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->dropColumn(['staff_message', 'staff_announcement', 'staff_handover', 'staff_acknowledged']);
        });
    }
};

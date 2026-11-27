<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            foreach (['hk_checkout_handoff','hk_arrival_urgent','hk_overdue','lost_found_registered'] as $kind) {
                $table->boolean($kind)->default(true);
            }
        });
    }
    public function down(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->dropColumn(['hk_checkout_handoff','hk_arrival_urgent','hk_overdue','lost_found_registered']);
        });
    }
};

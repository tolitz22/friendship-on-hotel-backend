<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->boolean('daily_close_completed')->default(true);
            $table->boolean('daily_close_blocked')->default(true);
            $table->boolean('closing_cashier_unreconciled')->default(true);
            $table->boolean('closing_payment_pending')->default(true);
            $table->boolean('closing_journal_integrity')->default(true);
            $table->boolean('closing_no_show_unresolved')->default(true);
            $table->boolean('closing_room_night_issue')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->dropColumn(['daily_close_completed', 'daily_close_blocked', 'closing_cashier_unreconciled', 'closing_payment_pending', 'closing_journal_integrity', 'closing_no_show_unresolved', 'closing_room_night_issue']);
        });
    }
};

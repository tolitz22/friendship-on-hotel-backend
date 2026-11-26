<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->boolean('guest_check_in')->default(true);
            $table->boolean('guest_check_out')->default(true);
            $table->boolean('booking_cancelled')->default(true);
            $table->boolean('booking_no_show')->default(true);
            $table->boolean('stay_extended')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->dropColumn(['guest_check_in', 'guest_check_out', 'booking_cancelled', 'booking_no_show', 'stay_extended']);
        });
    }
};

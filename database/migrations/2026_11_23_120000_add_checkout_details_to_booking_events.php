<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reservation_booking_events', function (Blueprint $table): void {
            $table->string('note', 500)->nullable();
            $table->dateTime('confirmed_departure_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_booking_events', function (Blueprint $table): void {
            $table->dropColumn(['note', 'confirmed_departure_at']);
        });
    }
};

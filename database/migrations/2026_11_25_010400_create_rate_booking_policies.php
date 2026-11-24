<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rate_booking_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('rate_plan_id')->constrained('room_rate_plans')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('deposit_kind', 20);
            $table->unsignedBigInteger('deposit_value');
            $table->unsignedSmallInteger('free_cancel_days');
            $table->string('late_cancel_kind', 20);
            $table->unsignedBigInteger('late_cancel_value');
            $table->string('no_show_kind', 20);
            $table->unsignedBigInteger('no_show_value');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'rate_plan_id', 'version'], 'rate_booking_policy_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_booking_policies');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_readiness_policies', function (Blueprint $table): void {
            $table->foreignId('hotel_id')->primary()->constrained()->restrictOnDelete();
            $table->boolean('enforce_at_check_in')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('change_reason', 500);
            $table->timestamps();
        });
        Schema::create('hotel_readiness_policy_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->boolean('previous_enabled');
            $table->boolean('next_enabled');
            $table->unsignedInteger('revision');
            $table->string('reason', 500);
            $table->timestamp('created_at');
            $table->index(['hotel_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_readiness_policy_events');
        Schema::dropIfExists('hotel_readiness_policies');
    }
};

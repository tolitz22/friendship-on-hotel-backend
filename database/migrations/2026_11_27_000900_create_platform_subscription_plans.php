<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 40)->unique();
            $table->string('description', 500)->nullable();
            $table->char('currency', 3)->default('PHP');
            $table->unsignedBigInteger('monthly_minor');
            $table->unsignedBigInteger('annual_minor');
            $table->unsignedInteger('room_limit')->nullable();
            $table->unsignedInteger('staff_limit')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('platform_subscription_plan_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('platform_subscription_plans')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['plan_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_plan_changes');
        Schema::dropIfExists('platform_subscription_plans');
    }
};

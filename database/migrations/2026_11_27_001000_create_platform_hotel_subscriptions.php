<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_hotel_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('platform_subscription_plans')->restrictOnDelete();
            $table->unsignedInteger('plan_revision');
            $table->string('plan_name', 100);
            $table->char('currency', 3);
            $table->string('cycle', 12);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedInteger('room_limit')->nullable();
            $table->unsignedInteger('staff_limit')->nullable();
            $table->string('kind', 12);
            $table->date('starts_on');
            $table->date('expires_on');
            $table->boolean('trial_used')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('platform_hotel_subscription_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('platform_hotel_subscriptions')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 20);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['subscription_id', 'revision'], 'platform_hotel_sub_change_revision_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_hotel_subscription_changes');
        Schema::dropIfExists('platform_hotel_subscriptions');
    }
};

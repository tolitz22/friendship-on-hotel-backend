<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('push_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('notification_id', 160);
            $table->string('kind', 40);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at');
            $table->timestamps();
            $table->unique(['push_subscription_id', 'notification_id'], 'push_delivery_event_unique');
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_deliveries', function (Blueprint $table): void {
            $table->index(['push_subscription_id', 'status', 'available_at'], 'push_delivery_scope_ready_idx');
            $table->index(['status', 'updated_at'], 'push_delivery_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::table('push_deliveries', function (Blueprint $table): void {
            $table->dropIndex('push_delivery_scope_ready_idx');
            $table->dropIndex('push_delivery_retention_idx');
        });
    }
};

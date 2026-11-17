<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('finance_gateway_events', function (Blueprint $table): void {
            // Nullable only for migration compatibility with already-ingested,
            // unbound events. New events must always supply a hotel ID.
            $table->foreignId('hotel_id')->nullable()
                ->constrained('hotels')->restrictOnDelete();
            $table->index(['hotel_id', 'processing_status', 'id'], 'finance_events_hotel_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_gateway_events', function (Blueprint $table): void {
            $table->dropIndex('finance_events_hotel_status_idx');
            $table->dropConstrainedForeignId('hotel_id');
        });
    }
};

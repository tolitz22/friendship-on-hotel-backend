<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paymongo_sandbox_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('event_id', 160)->unique();
            $table->string('event_type', 100);
            $table->char('payload_sha256', 64);
            $table->string('resource_id', 160)->nullable();
            $table->string('reference', 160)->nullable();
            $table->string('processing_status', 32)->default('unmapped');
            $table->timestamps();
            $table->index(['hotel_id', 'created_at'], 'paymongo_sandbox_hotel_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymongo_sandbox_events');
    }
};

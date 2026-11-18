<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('folio_payment_duplicate_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id');
            $table->foreignId('folio_id');
            $table->foreignId('payment_record_id');
            $table->foreignId('matched_payment_id');
            $table->foreignId('approved_by');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'payment_record_id'], 'payment_override_unique');
            $table->index(['hotel_id', 'folio_id']);
        });
        Schema::create('folio_payment_voids', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id');
            $table->foreignId('folio_id');
            $table->foreignId('payment_record_id');
            $table->foreignId('voided_by');
            $table->string('request_key', 100);
            $table->string('original_status', 30);
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'payment_record_id'], 'payment_void_once');
            $table->unique(['hotel_id', 'request_key'], 'payment_void_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_payment_voids');
        Schema::dropIfExists('folio_payment_duplicate_overrides');
    }
};

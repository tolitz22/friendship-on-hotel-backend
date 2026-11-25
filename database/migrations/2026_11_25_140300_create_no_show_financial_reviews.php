<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('no_show_financial_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('reservation_record_id');
            $table->unsignedBigInteger('folio_id');
            $table->unsignedBigInteger('actor_id');
            $table->char('review_fingerprint', 64);
            $table->string('currency', 3);
            $table->unsignedBigInteger('room_subtotal_minor');
            $table->unsignedBigInteger('fee_minor');
            $table->unsignedBigInteger('credit_minor');
            $table->unsignedBigInteger('credit_adjustment_id')->nullable();
            $table->unsignedBigInteger('fee_adjustment_id')->nullable();
            $table->json('review_snapshot');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'reservation_record_id'], 'no_show_review_booking_unique');
            $table->unique(['hotel_id', 'folio_id'], 'no_show_review_folio_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('no_show_financial_reviews');
    }
};

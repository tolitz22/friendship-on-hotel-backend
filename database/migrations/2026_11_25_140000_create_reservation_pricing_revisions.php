<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservation_pricing_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_record_id')->constrained('reservation_records')->restrictOnDelete();
            $table->foreignId('folio_id')->constrained('reservation_folios')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('review_fingerprint', 64);
            $table->date('old_check_in');
            $table->date('old_check_out');
            $table->date('new_check_in');
            $table->date('new_check_out');
            $table->char('currency', 3);
            $table->unsignedBigInteger('original_subtotal_minor');
            $table->unsignedBigInteger('replacement_subtotal_minor');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'review_fingerprint'], 'pricing_revision_review_unique');
            $table->index(['hotel_id', 'reservation_record_id'], 'pricing_revision_booking_idx');
        });

        Schema::create('reservation_pricing_revision_nights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('reservation_pricing_revisions')->restrictOnDelete();
            $table->foreignId('original_entry_id')->nullable()->constrained('folio_entries')->restrictOnDelete();
            $table->foreignId('rate_plan_id')->constrained('room_rate_plans')->restrictOnDelete();
            $table->date('stay_date');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->string('rate_source', 100)->nullable();
            $table->timestamp('created_at');
            $table->unique(['revision_id', 'kind', 'stay_date'], 'pricing_revision_night_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_pricing_revision_nights');
        Schema::dropIfExists('reservation_pricing_revisions');
    }
};

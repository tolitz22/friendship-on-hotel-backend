<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_gateway_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 48);
            $table->string('event_id', 160);
            $table->char('payload_sha256', 64);
            $table->string('event_type', 100);
            $table->dateTime('provider_occurred_at')->nullable();
            $table->string('verification_method', 40);
            $table->string('processing_status', 32)->default('unmapped');
            $table->text('payload_json');
            $table->timestamps();
            $table->unique(['provider', 'event_id'], 'finance_events_provider_id_unique');
            $table->index(['provider', 'created_at'], 'finance_events_provider_created_idx');
        });

        Schema::create('finance_gateway_event_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->unique()
                ->constrained('finance_gateway_events')->restrictOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_record_id')->constrained('folio_payment_records')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('assessment', 32); // reference_match, mismatch
            $table->string('note', 500);
            $table->timestamps();
            $table->index(['hotel_id', 'payment_record_id'], 'finance_gateway_reviews_scope_idx');
        });

        Schema::table('hotel_charge_policy_versions', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_charge_policy_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('review_note');
        });
        Schema::dropIfExists('finance_gateway_event_reviews');
        Schema::dropIfExists('finance_gateway_events');
    }
};

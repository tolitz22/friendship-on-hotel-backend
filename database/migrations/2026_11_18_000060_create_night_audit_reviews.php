<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('night_audit_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 24); // reviewed, flagged; NOT a financial close
            $table->unsignedBigInteger('posted_room_charges_minor');
            $table->unsignedInteger('scheduled_room_nights');
            $table->unsignedInteger('priced_room_nights');
            $table->unsignedInteger('room_count');
            $table->char('currency', 3);
            $table->char('snapshot_sha256', 64);
            $table->longText('snapshot_json');
            $table->text('manager_note');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->unique(['hotel_id', 'business_date'], 'night_audit_review_hotel_date_unique');
        });
        Schema::create('night_audit_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('night_audit_review_id')->constrained('night_audit_reviews')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->text('note');
            $table->timestamp('created_at');
            $table->index(['hotel_id','night_audit_review_id','id'], 'night_audit_review_events_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('night_audit_review_events');
        Schema::dropIfExists('night_audit_reviews');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('no_show_room_correction_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('reservation_records')->restrictOnDelete();
            $table->unsignedBigInteger('proposed_by');
            $table->date('business_date');
            $table->text('reason');
            $table->text('snapshot');
            $table->string('snapshot_sha256', 64);
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'booking_id'], 'ns_room_proposal_booking_unique');
        });
        Schema::create('no_show_room_correction_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('no_show_room_correction_proposals', indexName: 'ns_room_review_proposal_fk')->restrictOnDelete();
            $table->unsignedBigInteger('reviewed_by');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique('proposal_id', 'ns_room_review_proposal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('no_show_room_correction_reviews');
        Schema::dropIfExists('no_show_room_correction_proposals');
    }
};

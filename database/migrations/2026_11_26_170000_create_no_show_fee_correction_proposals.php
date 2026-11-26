<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('no_show_fee_correction_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('reservation_records')->restrictOnDelete();
            $table->foreignId('clearance_id')->constrained('no_show_financial_clearances', indexName: 'ns_fee_proposal_clearance_fk')->restrictOnDelete();
            $table->unsignedBigInteger('proposed_by');
            $table->unsignedBigInteger('original_fee_minor');
            $table->unsignedBigInteger('replacement_fee_minor');
            $table->date('business_date');
            $table->text('reason');
            $table->longText('snapshot');
            $table->string('snapshot_sha256', 64);
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'clearance_id'], 'ns_fee_proposal_clearance_unique');
        });
        Schema::create('no_show_fee_correction_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('no_show_fee_correction_proposals', indexName: 'ns_fee_review_proposal_fk')->restrictOnDelete();
            $table->unsignedBigInteger('reviewed_by');
            $table->string('tax_classification', 24);
            $table->unsignedBigInteger('replacement_vat_minor');
            $table->unsignedBigInteger('replacement_revenue_minor');
            $table->text('tax_basis');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique('proposal_id', 'ns_fee_review_proposal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('no_show_fee_correction_reviews');
        Schema::dropIfExists('no_show_fee_correction_proposals');
    }
};

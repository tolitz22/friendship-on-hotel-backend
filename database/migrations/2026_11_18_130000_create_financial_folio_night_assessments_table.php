<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_folio_night_assessments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('reservation_record_id');
            $table->unsignedBigInteger('folio_id');
            $table->unsignedBigInteger('folio_entry_id');
            $table->unsignedBigInteger('tax_policy_id');
            $table->date('stay_date');
            $table->bigInteger('gross_minor');
            $table->bigInteger('eligible_gross_minor')->default(0);
            $table->string('benefit_kind', 16)->default('none');
            $table->string('evidence_reference', 160)->nullable();
            $table->char('payload_sha256', 64);
            $table->bigInteger('folio_credit_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('room_revenue_minor');
            $table->bigInteger('payable_minor');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('source_journal_id')->nullable();
            $table->unsignedBigInteger('benefit_journal_id')->nullable();
            $table->unsignedBigInteger('vat_journal_id')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'folio_entry_id'], 'folio_night_assessment_unique');
            $table->unique('source_journal_id', 'folio_night_source_journal_unique');
            $table->index(['hotel_id', 'stay_date', 'status'], 'folio_night_review_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_folio_night_assessments');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_folio_night_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('assessment_id');
            $table->string('benefit_kind', 16);
            $table->bigInteger('eligible_gross_minor');
            $table->string('evidence_reference', 160)->nullable();
            $table->bigInteger('folio_credit_minor');
            $table->bigInteger('vat_minor');
            $table->bigInteger('room_revenue_minor');
            $table->bigInteger('payable_minor');
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('folio_adjustment_id')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'assessment_id', 'status'], 'folio_night_correction_lookup');
            $table->unique('journal_id', 'folio_night_correction_journal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_folio_night_corrections');
    }
};

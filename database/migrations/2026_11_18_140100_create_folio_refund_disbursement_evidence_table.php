<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('folio_refund_disbursement_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('folio_id');
            $table->unsignedBigInteger('refund_request_id');
            $table->unsignedBigInteger('payment_record_id');
            $table->bigInteger('amount_minor');
            $table->string('external_reference', 160);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('recorded_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id','refund_request_id'], 'folio_refund_disburse_once');
            $table->unique(['hotel_id','external_reference'], 'folio_refund_external_ref_unique');
            $table->index(['hotel_id','folio_id','status'], 'folio_refund_disburse_lookup');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('folio_refund_disbursement_evidence');
    }
};

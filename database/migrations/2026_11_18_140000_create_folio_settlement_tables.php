<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('folio_settlement_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('folio_id');
            $table->unsignedBigInteger('payment_record_id');
            $table->string('evidence_kind', 32);
            $table->string('evidence_reference', 160);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'payment_record_id'], 'folio_payment_verify_once');
            $table->index(['hotel_id', 'folio_id', 'status'], 'folio_verification_lookup');
        });

        Schema::create('folio_settlement_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('folio_id');
            $table->unsignedBigInteger('payment_record_id');
            $table->unsignedBigInteger('folio_entry_id')->nullable();
            $table->string('payer_kind', 16);
            $table->string('payer_reference', 120);
            $table->bigInteger('amount_minor');
            $table->string('idempotency_key', 100);
            $table->unsignedBigInteger('allocated_by');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'idempotency_key'], 'folio_payment_allocation_once');
            $table->index(['hotel_id', 'folio_id', 'payment_record_id'], 'folio_payment_alloc_lookup');
        });

        Schema::create('folio_settlement_signoffs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('folio_id');
            $table->unsignedBigInteger('reservation_record_id');
            $table->unsignedBigInteger('approved_by');
            $table->bigInteger('net_charges_minor');
            $table->bigInteger('verified_allocated_minor');
            $table->char('snapshot_sha256', 64);
            $table->text('statement_snapshot');
            $table->timestamp('signed_at');
            $table->unique(['hotel_id', 'folio_id'], 'folio_settlement_signed_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_settlement_signoffs');
        Schema::dropIfExists('folio_settlement_allocations');
        Schema::dropIfExists('folio_settlement_verifications');
    }
};

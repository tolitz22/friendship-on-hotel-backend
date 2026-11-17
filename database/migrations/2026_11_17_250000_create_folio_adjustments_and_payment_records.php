<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('folio_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('folio_id')->constrained('reservation_folios')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->char('payload_sha256', 64);
            $table->string('kind', 20); // credit, debit
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'request_key'], 'folio_adjustments_request_unique');
            $table->index(['hotel_id', 'folio_id']);
        });

        Schema::create('folio_payment_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('folio_id')->constrained('reservation_folios')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->char('payload_sha256', 64);
            $table->string('method', 32); // cash, bank_transfer
            $table->string('reference', 120);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 32); // pending_review, staff_confirmed, rejected
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'request_key'], 'folio_payments_request_unique');
            $table->unique(['hotel_id', 'method', 'reference'], 'folio_payments_external_reference_unique');
            $table->index(['hotel_id', 'folio_id', 'status'], 'folio_payments_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_payment_records');
        Schema::dropIfExists('folio_adjustments');
    }
};

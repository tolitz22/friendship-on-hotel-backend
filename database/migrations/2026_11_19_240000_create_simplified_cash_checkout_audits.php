<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simplified_cash_checkout_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_record_id')->constrained('reservation_records')->restrictOnDelete();
            $table->foreignId('folio_id')->constrained('reservation_folios')->restrictOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('recorded_charges_minor');
            $table->unsignedBigInteger('net_cash_minor');
            $table->unsignedInteger('receipt_count');
            $table->longText('receipt_snapshot');
            $table->char('receipt_snapshot_sha256', 64);
            // Explicitly supplied by the checkout transaction. DATETIME avoids
            // MySQL versions that reject multiple required TIMESTAMP columns
            // without defaults (or implicitly auto-update the first one).
            $table->dateTime('checked_out_at');
            $table->dateTime('created_at');
            $table->unique(['hotel_id', 'reservation_record_id'], 'cash_checkout_once_per_booking');
            $table->index(['hotel_id', 'checked_out_at'], 'cash_checkout_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simplified_cash_checkout_audits');
    }
};

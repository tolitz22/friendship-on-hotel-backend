<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('historical_guest_deposit_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained(indexName: 'historical_deposit_hotel_fk')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('folio_payment_records', indexName: 'historical_deposit_payment_fk')->restrictOnDelete();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('proposed_by');
            $table->unsignedBigInteger('amount_minor');
            $table->date('business_date');
            $table->string('evidence_reference', 160);
            $table->text('reason');
            $table->text('snapshot');
            $table->string('snapshot_sha256', 64);
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'payment_id'], 'historical_deposit_payment_unique');
        });
        Schema::create('historical_guest_deposit_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('decision_id')->constrained('historical_guest_deposit_decisions', indexName: 'historical_deposit_approval_decision_fk')->restrictOnDelete();
            $table->unsignedBigInteger('reviewed_by');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique('decision_id', 'historical_deposit_approval_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_guest_deposit_approvals');
        Schema::dropIfExists('historical_guest_deposit_decisions');
    }
};

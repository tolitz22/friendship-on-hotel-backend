<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('currency', 3);
            $table->string('status', 24)->default('open');
            $table->unsignedBigInteger('opening_float_minor');
            $table->unsignedBigInteger('expected_cash_minor')->nullable();
            $table->unsignedBigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->text('closing_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('manager_note')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->index(['hotel_id','cashier_id','status'], 'cashier_shift_staff_idx');
            $table->index(['hotel_id','status','id'], 'cashier_shift_inbox_idx');
        });
        Schema::create('cashier_shift_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('folio_payment_records')->restrictOnDelete();
            $table->foreignId('reservation_record_id')->constrained('reservation_records')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('created_at');
            $table->index(['hotel_id','shift_id'], 'cashier_receipts_hotel_shift_idx');
        });
        Schema::create('cashier_shift_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->text('details');
            $table->timestamp('created_at');
            $table->index(['hotel_id','shift_id','id'], 'cashier_shift_event_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_events');
        Schema::dropIfExists('cashier_shift_receipts');
        Schema::dropIfExists('cashier_shifts');
    }
};

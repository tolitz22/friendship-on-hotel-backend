<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_cash_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->constrained('hotel_expenses')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_id')->unique()->constrained('financial_posting_journals')->restrictOnDelete();
            $table->foreignId('correction_id')->nullable()->constrained('hotel_expense_corrections')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->unsignedBigInteger('amount_minor');
            $table->string('evidence_reference', 160);
            $table->string('reason', 500);
            $table->date('business_date');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'request_key']);
            $table->index(['hotel_id', 'expense_id']);
            $table->index(['hotel_id', 'shift_id']);
        });
        Schema::create('expense_cash_return_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_return_id')->unique()->constrained('expense_cash_returns')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('note', 500);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_cash_return_reviews');
        Schema::dropIfExists('expense_cash_returns');
    }
};

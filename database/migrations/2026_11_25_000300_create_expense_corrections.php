<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_expense_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->constrained('hotel_expenses')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('kind', 32);
            $table->string('reason', 500);
            $table->unsignedBigInteger('corrected_amount_minor')->nullable();
            $table->string('corrected_department', 64)->nullable();
            $table->string('corrected_category', 64)->nullable();
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'request_key']);
            $table->index(['hotel_id', 'expense_id']);
        });
        Schema::create('hotel_expense_correction_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('correction_id')->unique()->constrained('hotel_expense_corrections')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('note', 500);
            $table->foreignId('journal_id')->nullable()->unique()->constrained('financial_posting_journals')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_expense_correction_decisions');
        Schema::dropIfExists('hotel_expense_corrections');
    }
};

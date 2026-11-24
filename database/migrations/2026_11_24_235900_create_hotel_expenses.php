<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_id')->unique()->constrained('financial_posting_journals')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('payee', 160);
            $table->string('description', 255);
            $table->string('department', 64);
            $table->string('category', 64);
            $table->date('service_date');
            $table->date('business_date');
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('payment_source', 32);
            $table->string('review_status', 24);
            $table->string('invoice_reference', 120)->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'request_key']);
            $table->index(['hotel_id', 'business_date']);
        });
        Schema::create('cashier_expense_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->foreignId('expense_id')->unique()->constrained('hotel_expenses')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('created_at');
            $table->index(['hotel_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_expense_payouts');
        Schema::dropIfExists('hotel_expenses');
    }
};

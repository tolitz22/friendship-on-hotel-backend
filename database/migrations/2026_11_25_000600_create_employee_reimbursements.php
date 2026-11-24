<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_expense_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('employee_name_snapshot', 160);
            $table->string('merchant', 160);
            $table->string('description', 255);
            $table->string('department', 32);
            $table->string('category', 64);
            $table->string('receipt_reference', 160);
            $table->string('normalized_receipt_reference', 160);
            $table->string('normalized_merchant', 160);
            $table->date('service_date');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'request_key'], 'employee_claim_request_unique');
            $table->unique(['hotel_id', 'normalized_merchant', 'normalized_receipt_reference'], 'employee_claim_receipt_unique');
            $table->index(['hotel_id', 'employee_id']);
        });
        Schema::create('employee_expense_claim_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('claim_id')->unique()->constrained('employee_expense_claims')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_id')->nullable()->unique()->constrained('financial_posting_journals')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('decision', 16);
            $table->string('note', 500);
            $table->date('business_date')->nullable();
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'request_key'], 'employee_decision_request_unique');
        });
        Schema::create('employee_reimbursement_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('claim_id')->constrained('employee_expense_claims')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_id')->unique()->constrained('financial_posting_journals')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('cashier_shifts')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('payment_source', 32);
            $table->string('evidence_reference', 160);
            $table->unsignedBigInteger('amount_minor');
            $table->date('business_date');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'request_key'], 'employee_reimbursement_request_unique');
            $table->index(['hotel_id', 'claim_id']);
            $table->index(['hotel_id', 'business_date']);
            $table->index(['hotel_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('employee_reimbursement_payments')->exists()
            || DB::table('employee_expense_claim_decisions')->exists()
            || DB::table('employee_expense_claims')->exists()) {
            throw new \RuntimeException('Employee reimbursement history exists; destructive rollback is prohibited.');
        }
        Schema::dropIfExists('employee_reimbursement_payments');
        Schema::dropIfExists('employee_expense_claim_decisions');
        Schema::dropIfExists('employee_expense_claims');
    }
};

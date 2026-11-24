<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 160);
            $table->string('contact_name', 160)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 64)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'normalized_name'], 'supplier_hotel_name_unique');
            $table->index(['hotel_id', 'is_active']);
        });
        Schema::create('supplier_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('hotel_suppliers')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_id')->unique()->constrained('financial_posting_journals')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('invoice_reference', 120);
            $table->string('normalized_invoice_reference', 120);
            $table->string('supplier_name_snapshot', 160);
            $table->string('description', 255);
            $table->string('department', 32);
            $table->string('category', 64);
            $table->date('invoice_date');
            $table->date('service_date');
            $table->date('due_date');
            $table->date('business_date');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'request_key'], 'supplier_bill_request_unique');
            $table->unique(['hotel_id', 'supplier_id', 'normalized_invoice_reference'], 'supplier_invoice_unique');
            $table->index(['hotel_id', 'due_date']);
            $table->index(['hotel_id', 'business_date']);
        });
        Schema::create('supplier_bill_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('bill_id')->constrained('supplier_bills')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_id')->unique()->constrained('financial_posting_journals')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('payment_source', 32);
            $table->foreignId('shift_id')->nullable()->constrained('cashier_shifts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('evidence_reference', 160);
            $table->date('business_date');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'request_key'], 'supplier_payment_request_unique');
            $table->index(['hotel_id', 'bill_id']);
            $table->index(['hotel_id', 'business_date']);
        });
    }

    public function down(): void
    {
        // Do not permit a deployment rollback to destroy financial history.
        if (DB::table('supplier_bills')->exists() || DB::table('supplier_bill_payments')->exists()
            || DB::table('hotel_suppliers')->exists()) {
            throw new \RuntimeException('Supplier records exist; destructive rollback is prohibited.');
        }
        Schema::dropIfExists('supplier_bill_payments');
        Schema::dropIfExists('supplier_bills');
        Schema::dropIfExists('hotel_suppliers');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A single locked row per property is the serialization point for day close.
        Schema::create('financial_business_clocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained('hotels')->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 16)->default('open'); // open | closing
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });

        Schema::create('financial_accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open'); // open | closed
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'starts_on'], 'fin_period_hotel_start_uq');
            $table->index(['hotel_id', 'status', 'ends_on'], 'fin_period_hotel_status_end_idx');
        });

        Schema::create('financial_posting_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained('financial_accounting_periods')->restrictOnDelete();
            $table->date('business_date');
            $table->string('source_type', 64);
            $table->string('source_reference', 128);
            $table->string('idempotency_key', 128);
            $table->foreignId('reverses_journal_id')->nullable()->constrained('financial_posting_journals')->restrictOnDelete();
            $table->string('currency', 3)->default('PHP');
            $table->string('description', 255);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['hotel_id', 'idempotency_key'], 'fin_journal_hotel_idem_uq');
            $table->index(['hotel_id', 'business_date', 'posted_at'], 'fin_journal_hotel_date_posted_idx');
        });

        Schema::create('financial_posting_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('financial_posting_journals')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('account_code', 64);
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->string('description', 255)->nullable();
            $table->unique(['journal_id', 'line_number'], 'fin_line_journal_number_uq');
            $table->index(['account_code', 'journal_id'], 'fin_line_account_journal_idx');
        });

        Schema::create('financial_day_closes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 16); // completed; no partial close committed
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->json('reconciliation_snapshot');
            $table->timestamps();
            $table->unique(['hotel_id', 'business_date'], 'fin_close_hotel_date_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_day_closes');
        Schema::dropIfExists('financial_posting_lines');
        Schema::dropIfExists('financial_posting_journals');
        Schema::dropIfExists('financial_accounting_periods');
        Schema::dropIfExists('financial_business_clocks');
    }
};

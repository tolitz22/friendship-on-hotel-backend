<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('no_show_financial_clearances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('review_id')->constrained('no_show_financial_reviews')->restrictOnDelete();
            $table->unsignedBigInteger('reviewed_by');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('vat_minor');
            $table->unsignedBigInteger('revenue_minor');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->date('business_date');
            $table->text('tax_basis');
            $table->timestamp('created_at');
            $table->unique('review_id');
            $table->unique('journal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('no_show_financial_clearances');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_tax_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('classification', 24);
            $table->string('price_mode', 16);
            $table->unsignedInteger('rate_bps');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'status', 'effective_from'], 'tax_policy_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_tax_policies');
    }
};

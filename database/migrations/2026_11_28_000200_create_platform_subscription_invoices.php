<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('platform_hotel_subscriptions')->restrictOnDelete();
            $table->unsignedInteger('agreement_revision');
            $table->string('reference', 40)->unique();
            $table->string('hotel_name', 160);
            $table->string('plan_name', 100);
            $table->char('currency', 3);
            $table->string('cycle', 12);
            $table->unsignedBigInteger('amount_minor');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issued_on');
            $table->date('due_on');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['subscription_id', 'agreement_revision'], 'platform_sub_invoice_revision_uq');
            $table->index(['hotel_id', 'due_on'], 'platform_sub_invoice_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_invoices');
    }
};
